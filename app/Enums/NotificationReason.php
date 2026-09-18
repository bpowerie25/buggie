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

    /** Sensible defaults: enough to be useful, not enough to be ignored. */
    public function defaultEnabled(): bool
    {
        return true;
    }
}
