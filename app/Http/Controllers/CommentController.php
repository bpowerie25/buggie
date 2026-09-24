<?php

namespace App\Http\Controllers;

use App\Actions\AddComment;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Support\Issues\ClientConversation;
use App\Support\RichText\TiptapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(StoreCommentRequest $request, Issue $issue, AddComment $action): RedirectResponse
    {
        $this->authorize('comment', $issue);

        $internal = $request->boolean('is_internal');

        // A client cannot write an internal note, whatever the form posted.
        if ($internal) {
            $this->authorize('commentInternally', $issue);
        }

        $action->handle($issue, [
            'body' => $request->array('body'),
            'is_internal' => $internal,
        ], $request->user());

        return back();
    }

    /**
     * Reply to the client in public and wait on them. Staff only: it moves the issue,
     * and only staff change state.
     */
    public function await(StoreCommentRequest $request, Issue $issue, ClientConversation $conversation): RedirectResponse
    {
        $this->authorize('update', $issue);
        $this->authorize('comment', $issue);

        $conversation->replyAndAwait($issue, $request->array('body'), $request->user());

        return back()->with('success', 'Replied. Waiting on the client.');
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate(['body' => ['required', 'array']]);

        $comment->update([
            'body' => $validated['body'],
            'body_text' => TiptapDocument::toPlainText($validated['body']),
            'edited_at' => now(),
        ]);

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back();
    }
}
