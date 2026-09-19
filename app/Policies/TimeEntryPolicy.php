<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * Time is the team's business and nobody else's.
 *
 * There is deliberately no "clients can see time" switch. How long something took is
 * an input to an invoice, not a status update, and an agency that wants a client to
 * see hours sends them an invoice. A per-project toggle would mean every screen,
 * every export and every API response growing a branch that has to be right every
 * time — for a thing nobody asked for.
 */
class TimeEntryPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->role($user)?->isStaff() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Your own entry is yours to remove. Anyone else's needs the authority that
     * manages the workspace, because deleting somebody's logged hours changes what
     * they are paid for.
     */
    public function delete(User $user, TimeEntry $entry): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $entry->user_id === $user->id
            || ($this->role($user)?->canManageProjects() ?? false);
    }

    public function update(User $user, TimeEntry $entry): bool
    {
        return $this->delete($user, $entry);
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
