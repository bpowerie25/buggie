<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Label;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

class LabelPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    /** Managing the workspace vocabulary is staff work; clients see labels on their
     * own issues and nowhere else. */
    public function viewAny(User $user): bool
    {
        return $this->role($user)?->isStaff() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->isStaff() ?? false;
    }

    public function update(User $user, Label $label): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Label $label): bool
    {
        return $this->role($user)?->canManageProjects() ?? false;
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
