<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function update(User $user, Workspace $workspace): bool
    {
        return $user->membershipIn($workspace)?->canManageWorkspace() ?? false;
    }

    /** Money is the owner's business alone. */
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $user->membershipIn($workspace) === WorkspaceRole::Owner;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->id === $workspace->owner_id;
    }
}
