<?php

namespace App\Enums;

enum NotificationReason: string
{
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Commented = 'commented';
    case StatusChanged = 'status';
    case Reported = 'reported';

    /**
     * Due soon and overdue share one reason, and therefore one switch.
     *
     * Splitting them would offer "warn me beforehand but never tell me it is late",
     * which nobody wants and everybody would have to read past. The digest line says
     * which it is; the preference only answers whether due dates may write to you.
     */
    case DueDate = 'due';

    /** The team replied and is waiting on you. Sent to the clients who can see it. */
    case AwaitingReply = 'awaiting_reply';

    /** A client answered. Sent to the assignee, or failing that the staff watching. */
    case ClientReplied = 'client_replied';

    /** Still waiting on your reply, after the project's reminder period. */
    case ClientReminder = 'client_reminder';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned to you',
            self::Mentioned => 'You were mentioned',
            self::Commented => 'New comment',
            self::StatusChanged => 'Status changed',
            self::Reported => 'Activity on an issue you reported',
            self::DueDate => 'Due soon, or overdue',
            self::AwaitingReply => 'Waiting on your reply',
            self::ClientReplied => 'A client replied',
            self::ClientReminder => 'Reminders about a reply',
        };
    }

    /** What switching this off actually stops, in the words of the person reading. */
    public function description(): string
    {
        return match ($this) {
            self::Assigned => 'Somebody gives you an issue to deal with.',
            self::Mentioned => 'Somebody writes your name in a comment.',
            self::Commented => 'A new comment on an issue you are watching.',
            self::StatusChanged => 'An issue you are watching moves.',
            self::Reported => 'Activity on an issue you filed yourself.',
            self::DueDate => 'An issue you hold or watch is nearly due, or is late.',
            self::AwaitingReply => 'The team has replied to you and needs an answer to carry on.',
            self::ClientReplied => 'A client answers an issue you hold, or watch when nobody holds it.',
            self::ClientReminder => 'An issue has been waiting on your reply for a while.',
        };
    }

    /**
     * Opt-out rather than opt-in: silence should be chosen.
     *
     * Everything is on by default because a tracker nobody hears from is a tracker
     * nobody uses, and the screen to turn them off is one click away.
     */
    public function defaultEnabled(): bool
    {
        return true;
    }
}
