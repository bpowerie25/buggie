<?php

namespace App\Policies;

use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * Tenancy is handled by WorkspaceScope — an issue from another workspace is not
 * findable. These rules are about role, and about the client visibility plane.
 */
class IssuePolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Issue $issue): bool
    {
        $role = $this->role($user);

        if ($role === null) {
            return false;
        }

        if ($role->isStaff()) {
            return true;
        }

        // Clients: the issue must be marked client-visible AND sit in a project they
        // were granted. Either one alone is not enough.
        return $issue->visibility === IssueVisibility::Client
            && $user->projects()->whereKey($issue->project_id)->exists();
    }

    public function create(User $user): bool
    {
        // Clients may file issues; reporting is the point of giving them access.
        return $this->role($user) !== null;
    }

    /** Only staff change state. A client commenting is not a client triaging. */
    public function update(User $user, Issue $issue): bool
    {
        return $this->role($user)?->isStaff() ?? false;
    }

    public function comment(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    /** Internal notes are staff-only, both writing and reading. */
    public function commentInternally(User $user, Issue $issue): bool
    {
        return ($this->role($user)?->isStaff() ?? false) && $this->view($user, $issue);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $this->role($user)?->canManageWorkspace() ?? false;
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
