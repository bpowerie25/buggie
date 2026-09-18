<?php

namespace App\Actions;

use App\Enums\WatchReason;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;

class AddComment
{
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

            return $comment->load('author');
        });
    }
}
