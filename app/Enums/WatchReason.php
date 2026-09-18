<?php

namespace App\Enums;

enum WatchReason: string
{
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Commented = 'commented';
    case Reported = 'reported';
    case Manual = 'manual';
}
