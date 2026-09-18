<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

class InvitationPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        // Everyone can see who they are working with; only admins can change it.
        return $this->role($user)?->isStaff() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->canManageWorkspace() ?? false;
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->create($user);
    }

    public function removeMember(User $user): bool
    {
        return $this->create($user);
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
