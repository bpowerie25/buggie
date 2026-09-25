<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\PortalToken;
use App\Support\Issues\AuthorLabel;
use App\Support\Issues\ClientConversation;
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
                    'created_at' => $issue->created_at->toIso8601String(),
                ],
                'comments' => $issue->comments()
                    ->public()
                    ->with('author:id,name')
                    ->get()
                    ->map(fn (Comment $comment) => [
                        'id' => $comment->id,
                        'body' => $comment->body,
                        // The team as the workspace, as everywhere a client looks; the
                        // reporter's own words under their own address.
                        'author' => $comment->author
                            ? AuthorLabel::for($comment->author, $portal->workspace, readerIsStaff: false)
                            : $comment->displayName(),
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

            $comment = $issue->comments()->create([
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

            // The issue's visibility is left alone. The portal reaches this issue
            // through its token whatever the visibility, so the reporter loses
            // nothing; switching it to client-visible here used to show an issue the
            // team had kept internal to every client holding the project.

            $issue->touch();

            // A reporter without an account is still the client side of the
            // conversation, and their answer counts the same as a client's.
            app(ClientConversation::class)
                ->clientReplied($issue, null, $comment->body_text, $portal->email);
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
