<?php

namespace App\Enums;

/**
 * How sure we are who sent a report, from least to most.
 *
 * Only `verified` is proof: the customer's server signed the id and email with the
 * widget key's secret. `identified` is the page saying who it is, which anybody
 * running code in that page can say. `email_unverified` is whatever was typed.
 */
enum ReporterIdentity: string
{
    case Anonymous = 'anonymous';
    case EmailUnverified = 'email_unverified';
    case Identified = 'identified';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Anonymous => 'Anonymous',
            self::EmailUnverified => 'Unverified email',
            self::Identified => 'Identified',
            self::Verified => 'Verified',
        };
    }
}
