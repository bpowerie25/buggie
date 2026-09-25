<?php

namespace App\Http\Controllers\Api;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Enums\IssueVisibility;
use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Mail\EmailBody;
use App\Support\Mail\ReplyAddress;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
            : $this->addComment($tenancy, $token, $body);
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

        $subject = trim((string) $request->input('subject')) ?: 'Emailed report';

        $issue = $tenancy->run($project->workspace, function () use ($project, $subject, $body, $from) {
            // Never attributed from the From: header. The project address is printed on
            // the project screen and given to clients, and anybody can write any sender
            // — trusting it let a stranger file an issue as a named member, shared
            // with every client on the project. So an emailed issue is internal and
            // nobody's until the team triages it; who sent it is recorded in its text.
            return app(CreateIssue::class)->handle($project, [
                'title' => Str::limit($subject, 200, ''),
                'description' => $this->document($body, $from),
                'visibility' => IssueVisibility::Internal->value,
            ], null);
        });

        return response()->json(['issue' => $issue->key], 200);
    }

    /**
     * A reply to a digest, posted as the person the digest was sent to.
     *
     * The address is signed for one issue and one recipient (ReplyAddress), so the
     * From: header — which anybody can write — decides nothing. And they must still
     * be able to open the issue: access taken away after the email went out takes
     * the reply address with it.
     */
    private function addComment(Tenancy $tenancy, string $token, string $body): JsonResponse
    {
        $issued = ReplyAddress::verify($token);

        if ($issued === null) {
            // An address from before replies were signed, or one somebody made up.
            return response()->json(['message' => 'Unrecognised reply address; ignored.'], 200);
        }

        $issue = Issue::acrossAllWorkspaces()->with('workspace')->find($issued['issue']);
        $user = User::find($issued['user']);

        if ($issue === null || $issue->workspace === null || $user === null) {
            return response()->json(['message' => 'Unknown issue; ignored.'], 200);
        }

        $result = $tenancy->run($issue->workspace, function () use ($issue, $user, $body) {
            $issue = Issue::find($issue->id);

            if ($issue === null || ! Gate::forUser($user)->allows('view', $issue)) {
                return null;
            }

            $staff = $user->membershipIn($issue->workspace)?->isStaff() ?? false;

            // Staff replying by email write to the team, as the composer defaults to in
            // the app; anybody else writes in public. AddComment routes a client's
            // comment through ClientConversation, as it does in the app.
            $comment = app(AddComment::class)->handle($issue, [
                'body' => $this->document($body, null),
                'is_internal' => $staff,
            ], $user);

            $comment->forceFill(['source' => 'email'])->save();

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
        $key = config('buggie.mailgun_signing_key');

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

        if (! hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $signature)) {
            return false;
        }

        // And each signed delivery once. The timestamp alone lets a captured request
        // be sent again for five minutes; Mailgun's token is unique per delivery, so
        // having seen it before is a replay.
        return Cache::add('mailgun-token:'.hash('sha256', $token), true, 600);
    }
}
