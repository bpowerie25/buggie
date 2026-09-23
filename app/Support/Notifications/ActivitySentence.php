<?php

namespace App\Support\Notifications;

use App\Enums\NotificationReason;
use Illuminate\Support\Str;

/**
 * One line saying what happened, in the words somebody reads.
 *
 * Lives here rather than in IssueDigest because the digest email and the in-app
 * list say the same thing about the same row, and two copies of these sentences
 * would drift — a reason added to the enum would get a line in one place and a
 * match error in the other.
 */
final class ActivitySentence
{
    /** @param array<string, mixed> $data */
    public static function for(NotificationReason $reason, ?string $actor, array $data): string
    {
        $actor ??= 'Someone';

        return match ($reason) {
            NotificationReason::Assigned => "{$actor} assigned this to you.",
            NotificationReason::Mentioned => "{$actor} mentioned you.",
            NotificationReason::Commented => "{$actor} commented: "
                .Str::limit($data['excerpt'] ?? '', 140),
            NotificationReason::StatusChanged => "{$actor} moved this to "
                .($data['to'] ?? 'a new status').'.',
            NotificationReason::Reported => "{$actor} updated an issue you reported.",
            // Nobody did this one: the date arrived. Naming an actor here would put
            // somebody's name on a deadline they did not set.
            NotificationReason::DueDate => DueReminderSchedule::sentence(
                (int) ($data['days'] ?? 0),
            ),
        };
    }
}
