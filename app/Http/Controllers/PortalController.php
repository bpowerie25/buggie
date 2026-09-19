<?php

namespace App\Http\Controllers;

use App\Enums\IssueVisibility;
use App\Models\Comment;
use App\Models\PortalToken;
use App\Support\RichText\TiptapDocument;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reporter's view of their own bug — one issue, public comments only, no account.
 *
 * Runs on the central domain and outside every other guard, so the token is the whole
 * credential. It is therefore checked first, checked for expiry, and never trusted to
 * imply anything beyond the single issue it names.
 */
class PortalController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $portal = $this->resolve($token);

        if ($portal === null) {
            return Inertia::render('portal/invalid');
        }

        return app(Tenancy::class)->run($portal->workspace, function () use ($portal) {
            $issue = $portal->issue()->with(['status', 'project'])->firstOrFail();

            $portal->forceFill(['last_used_at' => now()])->saveQuietly();

            return Inertia::render('portal/show', [
                // What the reporter should see this as. They were using a shop, not
                // a bug tracker, and the agency that built it is not their concern.
                'brand' => $issue->project->branding(),
                'issue' => [
                    'key' => $issue->key,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'project' => $issue->project->name,
                    // Category, not the customer's status name: "Won't Fix" needs
                    // explaining to a reporter, "closed" does not.
                    'state' => $issue->status->category->isOpen() ? 'open' : 'closed',
                    'status' => $issue->status->name,
                    'created_at' => $issue->created_at->toIso8601String(),
                ],
                'comments' => $issue->comments()
                    ->public()
                    ->with('author:id,name')
                    ->get()
                    ->map(fn (Comment $comment) => [
                        'id' => $comment->id,
                        'body' => $comment->body,
                        'author' => $comment->displayName(),
                        'is_you' => $comment->author_email === $portal->email,
                        'created_at' => $comment->created_at->toIso8601String(),
                    ]),
                'token' => $portal->token,
                'email' => $portal->email,
            ]);
        });
    }

    public function comment(Request $request, string $token): RedirectResponse
    {
        $portal = $this->resolve($token);

        abort_if($portal === null, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // Unauthenticated endpoint: limit it.
        $bucket = 'portal:'.$portal->id;

        if (RateLimiter::tooManyAttempts($bucket, 10)) {
            return back()->withErrors(['body' => 'Too many messages just now. Please wait a moment.']);
        }

        RateLimiter::hit($bucket, 600);

        app(Tenancy::class)->run($portal->workspace, function () use ($portal, $validated) {
            $issue = $portal->issue;
            $document = [
                'type' => 'doc',
                'content' => array_map(
                    fn (string $line) => [
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => $line]],
                    ],
                    preg_split('/\n{2,}/', trim($validated['body'])) ?: [$validated['body']],
                ),
            ];

            $issue->comments()->create([
                'user_id' => null,
                'author_name' => null,
                'author_email' => $portal->email,
                'body' => $document,
                'body_text' => TiptapDocument::toPlainText($document),
                // A reporter can only ever write in public. There is no path from here
                // to an internal note.
                'is_internal' => false,
                'source' => 'portal',
            ]);

            // Someone replying to their own report expects to be kept informed.
            if ($issue->visibility !== IssueVisibility::Client) {
                $issue->forceFill(['visibility' => IssueVisibility::Client->value])->save();
            }

            $issue->touch();
        });

        return back()->with('success', 'Thanks — we have added your message.');
    }

    private function resolve(string $token): ?PortalToken
    {
        $portal = PortalToken::withoutGlobalScopes()
            ->with('workspace')
            ->where('token', $token)
            ->first();

        return $portal?->isValid() ? $portal : null;
    }
}
