<?php

namespace App\Support\Issues;

use App\Actions\AddComment;
use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\IssueVisibility;
use App\Enums\NotificationReason;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whose turn it is on an issue, carried by its status and never by its assignee.
 *
 * The team replies and waits: the reply is public, the clients who can see the
 * issue are told, and the issue moves to the project's awaiting-client status,
 * remembering where it was. The client answers: it goes back, the team is told, and
 * a badge says so until somebody on the team has looked. Nothing here ever touches
 * the assignee — a client was once made the assignee to ask them something, and
 * that is how the agency's work came to say it belonged to the client.
 */
class ClientConversation
{
    public function __construct(
        private UpdateIssue $updates,
        private Notifier $notifier,
        private ClientAudienceSummary $audience,
    ) {}

    /** Whether "Reply & await client" makes sense here at all. */
    public function canAwait(Issue $issue): bool
    {
        return $issue->visibility === IssueVisibility::Client
            && $issue->loadMissing('project')->project->awaitingClientStatus() !== null;
    }

    /**
     * Staff reply in public and wait on the client.
     *
     * @param  array<string, mixed>  $body
     */
    public function replyAndAwait(Issue $issue, array $body, User $staff): Comment
    {
        abort_unless($this->canAwait($issue), 422, 'This issue cannot wait on a client: it is internal, or its project has no awaiting-client status.');

        return DB::transaction(function () use ($issue, $body, $staff) {
            // Posted without the usual fan-out: who hears about this reply, and in
            // what words, is decided below.
            $comment = app(AddComment::class)->handle($issue, ['body' => $body, 'is_internal' => false], $staff, notify: false);

            $awaiting = $issue->project->awaitingClientStatus();
            $current = $issue->loadMissing('status')->status;

            // Already waiting: the reply is a nudge, and "where it was before" must
            // not become the waiting status itself.
            if (! $current->is_awaiting_client) {
                $issue->status_before_waiting_id = $current->id;
            }

            $issue->awaiting_client_since = now();
            $issue->client_reminded_at = null;
            $issue->auto_closed_at = null;
            $issue->client_replied_at = null;

            $this->updates->moveTo($issue, $awaiting, $staff, IssueEventType::AwaitingClient);

            $excerpt = $comment->body_text;

            // The clients who can see it, and only them. Asked of the same scope that
            // decides what they can open, so the audience and the notification agree.
            foreach ($this->audience->visibleTo($issue) as $client) {
                $this->notifier->record($client, $issue, NotificationReason::AwaitingReply, $staff, [
                    'excerpt' => $excerpt,
                    'from' => AuthorLabel::for($staff, $issue->workspace, readerIsStaff: false),
                ]);
            }

            // The rest of the team watching hears it as the comment it is.
            foreach ($this->staffWatchers($issue) as $watcher) {
                $this->notifier->record($watcher, $issue, NotificationReason::Commented, $staff, ['excerpt' => $excerpt]);
            }

            return $comment;
        });
    }

    /**
     * The client side answered: a Client member (web or email), or a reporter through
     * the portal, who has no account and so no User.
     *
     * If the issue was waiting on them — or was closed for want of their reply — it
     * goes back to where it was. Either way the team is told and the badge goes up.
     * Nothing else changes: not the assignee, not the audience.
     */
    public function clientReplied(Issue $issue, ?User $client, string $excerpt, ?string $name = null): void
    {
        $issue->loadMissing(['status', 'project']);

        $waiting = $issue->status->is_awaiting_client || $issue->auto_closed_at !== null;

        if ($waiting) {
            $back = $issue->statusBeforeWaiting;

            if ($back === null || $back->project_id !== $issue->project_id) {
                $back = $issue->project->defaultStatus();
            }

            $issue->status_before_waiting_id = null;
            $issue->awaiting_client_since = null;
            $issue->client_reminded_at = null;
            $issue->auto_closed_at = null;

            if ($back !== null) {
                $this->updates->moveTo($issue, $back, $client, IssueEventType::ClientReplied, [
                    'name' => $name,
                ]);
            }
        }

        // Written straight to the row: a badge going up is not an edit, and should
        // not move the issue up every "recently updated" list a second time.
        DB::table('issues')->where('id', $issue->id)->update(['client_replied_at' => now()]);
        $issue->client_replied_at = now();

        $from = $client?->name ?? $name ?? 'The client';
        $data = ['excerpt' => Str::limit($excerpt, 500), 'from' => $from];

        $told = $this->whoHearsAReply($issue);

        foreach ($told as $person) {
            $this->notifier->record($person, $issue, NotificationReason::ClientReplied, $client, $data);
        }

        // Everybody else watching hears it as an ordinary comment, as before. Nobody
        // at all is a real outcome: then the badge and Triage are how it is noticed.
        $issue->loadMissing('watchers');

        foreach ($issue->watchers as $watcher) {
            if (! $told->contains('id', $watcher->id)) {
                $this->notifier->record($watcher, $issue, NotificationReason::Commented, $client, $data);
            }
        }
    }

    /** Somebody on the team has looked at it or answered, so the badge comes down. */
    public function seenByStaff(Issue $issue): void
    {
        if ($issue->client_replied_at === null) {
            return;
        }

        DB::table('issues')->where('id', $issue->id)->update(['client_replied_at' => null]);
        $issue->client_replied_at = null;
    }

    /**
     * The assignee, who after "assignee must be staff" is always on the team; the
     * staff watching when nobody holds it; nobody otherwise.
     *
     * @return Collection<int, User>
     */
    private function whoHearsAReply(Issue $issue): Collection
    {
        $assignee = $issue->loadMissing('assignee')->assignee;

        if ($assignee !== null && Assignable::isAssignable($assignee, $issue->workspace)) {
            return collect([$assignee]);
        }

        return $this->staffWatchers($issue);
    }

    /** @return Collection<int, User> */
    private function staffWatchers(Issue $issue): Collection
    {
        return $issue->loadMissing('watchers')->watchers
            ->filter(fn (User $watcher) => Assignable::isAssignable($watcher, $issue->workspace))
            ->values();
    }
}
