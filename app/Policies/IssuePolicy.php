<?php

namespace App\Policies;

use App\Enums\IssueVisibility;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Auth\Access\Response;

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

    /**
     * Denied as NOT FOUND rather than forbidden.
     *
     * A 403 confirms the issue exists. Keys are sequential per project, so a client
     * who can see WEB-4 could walk WEB-1..WEB-500 and learn how many issues sit in
     * projects they were never granted — which is a fair measure of how much work
     * the agency is doing for its other clients.
     */
    public function view(User $user, Issue $issue): Response
    {
        $role = $this->role($user);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($role->isStaff()) {
            return Response::allow();
        }

        // Client-visible and in a project they hold. Either one alone is not enough,
        // and this is true for every client tier.
        if ($issue->visibility !== IssueVisibility::Client) {
            return Response::denyAsNotFound();
        }

        $grant = $user->projects()->whereKey($issue->project_id)->first();

        if ($grant === null) {
            return Response::denyAsNotFound();
        }

        /*
         * A client manager sees the whole client-visible project. Anybody else sees
         * the part of it they are in — what they reported, or were drawn into.
         *
         * The same rule as Issue::scopeVisibleToClient, expressed for one issue
         * rather than for a list. Two expressions of one rule is a risk, so both are
         * tested against the same cases and a change to either without the other
         * fails those tests.
         */
        $tier = ProjectRole::tryFrom((string) $grant->pivot->role) ?? ProjectRole::Client;

        if ($tier->seesEveryClientIssue()) {
            return Response::allow();
        }

        $theirs = $issue->reporter_id === $user->id
            || $issue->watchers()->whereKey($user->id)->exists();

        return $theirs ? Response::allow() : Response::denyAsNotFound();
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

    public function comment(User $user, Issue $issue): Response
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
        return $this->deleteAny($user);
    }

    /**
     * Reaching the trash at all, and undeleting from it.
     *
     * The same authority as deleting rather than a lesser one: somebody who cannot
     * remove an issue should not be able to bring a client's back either, and the list
     * of what has been deleted is itself worth protecting — it is a list of what
     * somebody wanted gone.
     */
    public function deleteAny(User $user): bool
    {
        return $this->role($user)?->canManageWorkspace() ?? false;
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
