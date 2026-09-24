<?php

namespace App\Enums;

enum AccessRequestStatus: string
{
    case Pending = 'pending';

    /** An invitation went out. Whether it was accepted is the invitation's business. */
    case Approved = 'approved';

    case Declined = 'declined';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
