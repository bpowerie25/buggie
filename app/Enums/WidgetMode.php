<?php

namespace App\Enums;

/**
 * What identity a widget key accepts. The `mode` column existed long before it did
 * anything; these are what it now means.
 */
enum WidgetMode: string
{
    /** identify() is ignored. A typed email is kept, as an unverified one. */
    case Anonymous = 'anonymous';

    /** The default: identify() is taken, and a user_hash checked when one comes with it. */
    case Identified = 'identified';

    /** "Require verified identity": a report without a valid user_hash is refused. */
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Anonymous => 'Anonymous — ignore identify()',
            self::Identified => 'Identified — accept identify(), verify when signed',
            self::Verified => 'Require verified identity',
        };
    }
}
