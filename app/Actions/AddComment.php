<?php

namespace App\Actions;

use App\Enums\WatchReason;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Enums\NotificationReason;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;

class AddComment
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  array{body: array<string, mixed>, is_internal?: bool}  $attributes
     */
    public function handle(Issue $issue, array $attributes, User $author): Comment
    {
        return DB::transaction(function () use ($issue, $attributes, $author) {
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

            \App\Support\Webhooks\Webhooks::comment($issue, $author->name, $internal);

            // Chat hears about internal notes too, but only where somebody has said
            // the channel is the team's own. The text is never sent either way.
            \App\Support\Chat\ChatNotifications::comment($issue, $author->name, $internal);

            $this->notifier->watchers($issue, NotificationReason::Commented, $author, [
                'excerpt' => $comment->body_text,
                // Clients watching this issue must not be told about internal notes.
                'internal' => $internal,
            ]);

            foreach (TiptapDocument::mentionedUserIds($body) as $id) {
                if ($mentioned = User::find($id)) {
                    $this->notifier->record($mentioned, $issue, NotificationReason::Mentioned, $author);
                }
            }

            return $comment->load('author');
        });
    }
}
