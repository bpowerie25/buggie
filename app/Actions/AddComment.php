<?php

namespace App\Actions;

use App\Enums\NotificationReason;
use App\Enums\WatchReason;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Support\Chat\ChatNotifications;
use App\Support\Issues\AuthorLabel;
use App\Support\Issues\ClientConversation;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Facades\DB;

class AddComment
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  array{body: array<string, mixed>, is_internal?: bool}  $attributes
     * @param  bool  $notify  False when the caller decides who hears about it — see
     *                        ClientConversation::replyAndAwait().
     */
    public function handle(Issue $issue, array $attributes, User $author, bool $notify = true): Comment
    {
        return DB::transaction(function () use ($issue, $attributes, $author, $notify) {
            $body = $attributes['body'];

            $comment = $issue->comments()->create([
                'user_id' => $author->id,
                'body' => $body,
                'body_text' => TiptapDocument::toPlainText($body),
                // Internal unless explicitly made public. A client seeing an internal
                // note is the failure mode worth defaulting against.
                'is_internal' => $attributes['is_internal'] ?? true,
                'source' => 'web',
            ]);

            $issue->watch($author, WatchReason::Commented);

            foreach (TiptapDocument::mentionedUserIds($body) as $id) {
                $issue->watch(User::find($id), WatchReason::Mentioned);
            }

            $issue->touch();

            $internal = $comment->is_internal;
            $fromClient = AuthorLabel::role($author, $issue->workspace) === 'client';

            Webhooks::comment($issue, $author->name, $internal);

            // Chat hears about internal notes too, but only where somebody has said
            // the channel is the team's own. The text is never sent either way.
            ChatNotifications::comment($issue, $author->name, $internal);

            $conversation = app(ClientConversation::class);

            if ($fromClient) {
                // A client's word is always public, and it is their answer: the
                // conversation decides whether the issue moves and who is told.
                $conversation->clientReplied($issue, $author, $comment->body_text);
            } else {
                if ($notify) {
                    $this->notifier->watchers($issue, NotificationReason::Commented, $author, [
                        'excerpt' => $comment->body_text,
                        // Clients watching this issue must not be told about internal notes.
                        'internal' => $internal,
                    ]);
                }

                // Answering the client takes the "client replied" badge down. An
                // internal note does not: the client is still waiting to hear.
                if (! $internal) {
                    $conversation->seenByStaff($issue);
                }
            }

            foreach (TiptapDocument::mentionedUserIds($body) as $id) {
                if ($mentioned = User::find($id)) {
                    // Carries whether the note is internal, so a client mentioned in
                    // one is not emailed the team's note.
                    $this->notifier->record($mentioned, $issue, NotificationReason::Mentioned, $author, ['internal' => $internal]);
                }
            }

            return $comment->load('author');
        });
    }
}
