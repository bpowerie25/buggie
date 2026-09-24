<?php

namespace App\Enums;

enum WatchReason: string
{
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Commented = 'commented';
    case Reported = 'reported';
    case Manual = 'manual';
    /** Followed the original because their own issue was closed as its duplicate. */
    case Duplicate = 'duplicate';
}
