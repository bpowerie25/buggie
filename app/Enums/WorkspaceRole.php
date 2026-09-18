<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Client = 'client';

    /** Staff see every project in the workspace; clients are scoped to their own. */
    public function isStaff(): bool
    {
        return $this !== self::Client;
    }

    public function canManageWorkspace(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageProjects(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
