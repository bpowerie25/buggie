<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Label;
use App\Models\User;
use App\Support\Settings\Features;
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

    /**
     * Admins and owners always; other staff only when an admin has allowed it.
     *
     * The vocabulary is shared by every project, so changing it changes everybody's
     * filters and saved views. That is an admin's call to delegate, not a default.
     */
    public function create(User $user): bool
    {
        $role = $this->role($user);

        if ($role === null || ! $role->isStaff()) {
            return false;
        }

        return $role->canManageWorkspace()
            || Features::membersManageLabels($this->tenancy->current());
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
