<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * Tenancy is already enforced by WorkspaceScope — a project from another workspace
 * is not findable here at all. These checks are about role within the workspace.
 */
class ProjectPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Project $project): bool
    {
        $role = $this->role($user);

        if ($role === null) {
            return false;
        }

        // Staff reach every project; clients only the ones they were granted.
        return $role->isStaff()
            || $project->clients()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->canManageProjects() ?? false;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->create($user);
    }

    /**
     * Bringing issues in from a spreadsheet or another tracker, or updating them from
     * one. Day-to-day work rather than project setup, so anybody on the staff who can
     * see the project — not only whoever manages it. Never a client: an import writes
     * many issues at once, internal ones included.
     */
    public function import(User $user, Project $project): bool
    {
        return ($this->role($user)?->isStaff() ?? false) && $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->role($user)?->canManageWorkspace() ?? false;
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
