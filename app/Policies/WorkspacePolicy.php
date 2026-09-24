<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Registration\Registration;

class WorkspacePolicy
{
    /**
     * Anybody on an open install; only an operator otherwise. Checked in the action as
     * well as at the route, so no future entry point can create one without asking.
     */
    public function create(User $user): bool
    {
        return app(Registration::class)->mayCreateWorkspace($user);
    }

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
