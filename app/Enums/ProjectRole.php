<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Maintainer = 'maintainer';
    case Contributor = 'contributor';
    case Client = 'client';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
