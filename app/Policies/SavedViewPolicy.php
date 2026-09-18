<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\SavedView;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

class SavedViewPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function create(User $user): bool
    {
        return $this->role($user) !== null;
    }

    /**
     * Your own views are yours. A shared view belongs to the workspace, so changing
     * one changes what everybody sees — that needs staff.
     */
    public function update(User $user, SavedView $view): bool
    {
        return $view->user_id === $user->id
            || ($view->isShared() && ($this->role($user)?->isStaff() ?? false));
    }

    public function delete(User $user, SavedView $view): bool
    {
        return $this->update($user, $view);
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
