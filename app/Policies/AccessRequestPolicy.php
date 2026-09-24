<?php

namespace App\Policies;

use App\Models\AccessRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Gate;

/**
 * Deciding who gets in belongs to whoever can already invite — a workspace's owners
 * and admins — and, as the fallback, to the install's operators.
 */
class AccessRequestPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    /** This workspace's requests. */
    public function viewAny(User $user): bool
    {
        $workspace = $this->tenancy->current();

        return $workspace !== null
            && ($user->membershipIn($workspace)?->canManageWorkspace() ?? false);
    }

    /** Every request on the install, including those for no workspace. */
    public function viewAll(User $user): bool
    {
        return Gate::forUser($user)->allows('operate');
    }

    public function decide(User $user, AccessRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        if ($this->viewAll($user)) {
            return true;
        }

        // Compared by id as well as reached through the scope: a request for another
        // workspace, or for none, is never this admin's to decide.
        return $request->workspace_id !== null
            && $request->workspace_id === $this->tenancy->id()
            && $this->viewAny($user);
    }
}
