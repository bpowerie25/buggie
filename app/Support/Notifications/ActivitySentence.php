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
            // `from` is set when the reader is a client, and is the workspace's name
            // unless it has chosen to show its staff: see AuthorLabel.
            NotificationReason::AwaitingReply => ($data['from'] ?? $actor).' replied and is waiting on you: '
                .Str::limit($data['excerpt'] ?? '', 140),
            // A portal reporter has no account, so no actor; their name rides in data.
            NotificationReason::ClientReplied => ($data['from'] ?? $actor).' replied: '
                .Str::limit($data['excerpt'] ?? '', 140),
            // Nobody did this either: time passed.
            NotificationReason::ClientReminder => 'Still waiting on your reply'
                .(isset($data['since']) ? ' since '.$data['since'] : '').'.',
        };
    }
}
