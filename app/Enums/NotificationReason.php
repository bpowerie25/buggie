<?php

namespace App\Enums;

enum NotificationReason: string
{
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Commented = 'commented';
    case StatusChanged = 'status';
    case Reported = 'reported';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned to you',
            self::Mentioned => 'You were mentioned',
            self::Commented => 'New comment',
            self::StatusChanged => 'Status changed',
            self::Reported => 'Activity on an issue you reported',
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
