<?php

namespace App\Support\Notifications;

use App\Enums\NotificationReason;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\User;

/**
 * Records that someone should hear about something.
 *
 * Nothing is sent here. Rows accumulate and FlushNotifications turns each
 * person-plus-issue group into one message, so ten edits in a minute is one email.
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

        PendingNotification::create([
            'user_id' => $recipient->id,
            'issue_id' => $issue->id,
            'actor_id' => $actor?->id,
            'reason' => $reason->value,
            'data' => $data,
            'created_at' => now(),
        ]);
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
