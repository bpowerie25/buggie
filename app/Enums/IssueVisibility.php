<?php

namespace App\Enums;

enum IssueVisibility: string
{
    /** Staff only. The default: an issue is private until someone decides otherwise. */
    case Internal = 'internal';

    /** Visible to clients granted access to the project. */
    case Client = 'client';

    public function label(): string
    {
        return $this === self::Client ? 'Visible to client' : 'Internal only';
    }
}
