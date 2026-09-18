<?php

namespace App\Http\Controllers\Api;

use App\Actions\AddComment;
use App\Http\Controllers\Controller;
use App\Actions\CreateIssue;
use App\Enums\IssueVisibility;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Mail\EmailBody;
use App\Support\RichText\TiptapDocument;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mailgun inbound route webhook.
 *
 * Chosen over IMAP polling: a webhook beats a cron poll on latency and on failure
 * modes. Anyone can POST here, so the Mailgun signature is verified before anything
 * is read, and the routing token in the recipient address decides the tenant.
 */
class InboundMailController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $routing = EmailBody::parseRecipient(
            $request->input('recipient') ?? $request->input('To'),
        );

        if ($routing === null) {
            // 200 rather than an error: Mailgun retries failures, and this will never
            // succeed no matter how many times it is tried.
            return response()->json(['message' => 'No routing token; ignored.'], 200);
        }

        [$kind, $token] = $routing;

        $body = EmailBody::extract(
            $request->input('stripped-text') ?? $request->input('body-plain'),
        );

        if ($body === '') {
            return response()->json(['message' => 'Empty message; ignored.'], 200);
        }

        $from = $request->input('from') ?? $request->input('From');

        return $kind === 'bugs'
            ? $this->createIssue($tenancy, $token, $request, $body, $from)
            : $this->addComment($tenancy, $token, $body, $from);
    }

    private function createIssue(
        Tenancy $tenancy,
        string $token,
        Request $request,
        string $body,
        ?string $from,
    ): JsonResponse {
        $project = Project::withoutGlobalScopes()->where('inbound_token', $token)->first();

        if ($project === null) {
            return response()->json(['message' => 'Unknown project token; ignored.'], 200);
        }

        $email = EmailBody::senderEmail($from);
        $subject = trim((string) $request->input('subject')) ?: 'Emailed report';

        $issue = $tenancy->run($project->workspace, function () use ($project, $subject, $body, $email, $from) {
            // If the sender has an account here, the issue is properly theirs.
            $reporter = $email ? User::where('email', $email)->first() : null;
            $knownMember = $reporter?->belongsToWorkspace($project->workspace) ? $reporter : null;

            $document = $this->document($body, $from);

            return app(CreateIssue::class)->handle($project, [
                'title' => \Illuminate\Support\Str::limit($subject, 200, ''),
                'description' => $document,
                // From a stranger: keep it internal until someone triages it.
                'visibility' => $knownMember
                    ? IssueVisibility::Client->value
                    : IssueVisibility::Internal->value,
            ], $knownMember);
        });

        return response()->json(['issue' => $issue->key], 200);
    }

    private function addComment(
        Tenancy $tenancy,
        string $token,
        string $body,
        ?string $from,
    ): JsonResponse {
        // reply+{ISSUE-KEY}.{project token}
        if (! preg_match('/^(?<key>[a-z0-9]+-\d+)\.(?<project>[a-z0-9]+)$/i', $token, $m)) {
            return response()->json(['message' => 'Malformed reply token; ignored.'], 200);
        }

        $project = Project::withoutGlobalScopes()->where('inbound_token', $m['project'])->first();

        if ($project === null) {
            return response()->json(['message' => 'Unknown project token; ignored.'], 200);
        }

        $email = EmailBody::senderEmail($from);

        $result = $tenancy->run($project->workspace, function () use ($project, $m, $body, $email, $from) {
            $issue = Issue::where('project_id', $project->id)
                ->where('key', strtoupper($m['key']))
                ->first();

            if ($issue === null) {
                return null;
            }

            $user = $email ? User::where('email', $email)->first() : null;
            $isStaff = $user?->membershipIn($project->workspace)?->isStaff() ?? false;
            $document = $this->document($body, null);

            if ($user && $isStaff) {
                // Staff replying by email are writing to the team, matching what the
                // composer defaults to in the app.
                app(AddComment::class)->handle($issue, [
                    'body' => $document,
                    'is_internal' => true,
                ], $user);

                return $issue;
            }

            // Everyone else writes in public, attributed to their address.
            $issue->comments()->create([
                'user_id' => $user?->id,
                'author_name' => EmailBody::senderName($from),
                'author_email' => $email,
                'body' => $document,
                'body_text' => TiptapDocument::toPlainText($document),
                'is_internal' => false,
                'source' => 'email',
            ]);

            $issue->touch();

            return $issue;
        });

        return $result === null
            ? response()->json(['message' => 'Unknown issue; ignored.'], 200)
            : response()->json(['issue' => $result->key], 200);
    }

    /** @return array<string, mixed> */
    private function document(string $body, ?string $from): array
    {
        $paragraphs = array_map(
            fn (string $chunk) => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => trim($chunk)]],
            ],
            array_filter(preg_split('/\n{2,}/', $body) ?: [$body], fn ($c) => trim($c) !== ''),
        );

        if ($from !== null) {
            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Received by email from '.$from.'.']],
            ];
        }

        return ['type' => 'doc', 'content' => array_values($paragraphs)];
    }

    /**
     * Mailgun signs each webhook with timestamp + token, HMAC-SHA256 under the
     * account's signing key.
     */
    private function signatureIsValid(Request $request): bool
    {
        $key = config('buggy.mailgun_signing_key');

        // Without a configured key this endpoint would accept anything, so it accepts
        // nothing instead.
        if (! $key) {
            return false;
        }

        $timestamp = (string) $request->input('timestamp');
        $token = (string) $request->input('token');
        $signature = (string) $request->input('signature');

        if ($timestamp === '' || $token === '' || $signature === '') {
            return false;
        }

        // Reject replays of an old, valid signature.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $timestamp.$token, $key),
            $signature,
        );
    }
}
