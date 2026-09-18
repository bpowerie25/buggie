<?php

namespace App\Enums;

enum ReportState: string
{
    /** In the triage inbox, awaiting a decision. */
    case New = 'new';

    /** Became an issue. */
    case Promoted = 'promoted';

    /** Folded into an existing issue as another occurrence. */
    case Merged = 'merged';

    case Spam = 'spam';
    case Discarded = 'discarded';

    public function isTriaged(): bool
    {
        return $this !== self::New;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
