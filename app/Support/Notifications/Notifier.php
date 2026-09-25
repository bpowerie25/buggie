<?php

namespace App\Support\Notifications;

use App\Enums\NotificationReason;
use App\Models\InAppNotification;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Records that someone should hear about something.
 *
 * Nothing is sent here. Rows accumulate and FlushNotifications turns each
 * person-plus-issue group into one message, so ten edits in a minute is one email.
 *
 * Two rows are written, not one. The pending row is a send queue and is deleted the
 * moment its digest goes out; the in-app row is the record and stays until pruned.
 * Both are written here because this is the one place that already knows not to
 * notify somebody about their own actions, and not to tell a client about internal
 * work — a second write site would be a second place to forget.
 */
class Notifier
{
    /** @param array<string, mixed> $data */
    public function record(
        User $recipient,
        Issue $issue,
        NotificationReason $reason,
        ?User $actor = null,
        array $data = [],
    ): void {
        // Nobody needs telling about their own actions.
        if ($actor !== null && $actor->id === $recipient->id) {
            return;
        }

        if (! $recipient->wantsNotification($reason)) {
            return;
        }

        // Only somebody who could open the issue hears about it, and a client never
        // hears about internal activity — asked here, where every notification
        // passes, rather than trusted to each caller. A mention of somebody who is
        // not a member, an internal issue a client once watched, a note on an issue
        // a client can see but the note is internal: all stop at this line.
        if (! $this->isStaff($recipient, $issue)
            && (($data['internal'] ?? false) || ! Gate::forUser($recipient)->allows('view', $issue))) {
            return;
        }

        $row = [
            'user_id' => $recipient->id,
            'issue_id' => $issue->id,
            'actor_id' => $actor?->id,
            'reason' => $reason->value,
            'data' => $data,
            'created_at' => now(),
        ];

        PendingNotification::create($row);

        // The preference is honoured for both surfaces. Somebody who says "never
        // tell me about comments" is not asking to be told about them quietly.
        InAppNotification::create($row);
    }

    /**
     * Tell everyone watching an issue, except whoever caused it.
     *
     * @param  array<string, mixed>  $data
     */
    public function watchers(
        Issue $issue,
        NotificationReason $reason,
        ?User $actor = null,
        array $data = [],
    ): void {
        $issue->loadMissing('watchers');

        foreach ($issue->watchers as $watcher) {
            // A client watching an issue must not be told about internal activity.
            if (($data['internal'] ?? false) && ! $this->isStaff($watcher, $issue)) {
                continue;
            }

            $this->record($watcher, $issue, $reason, $actor, $data);
        }
    }

    private function isStaff(User $user, Issue $issue): bool
    {
        return $user->membershipIn($issue->workspace)?->isStaff() ?? false;
    }
}
