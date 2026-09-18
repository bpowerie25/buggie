<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Report;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * Triage is staff work. Reports contain raw, unreviewed context — screenshots of
 * whatever the reporter had on screen — so clients never see the inbox.
 */
class ReportPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->role($user)?->isStaff() ?? false;
    }

    public function view(User $user, Report $report): bool
    {
        return $this->viewAny($user);
    }

    public function triage(User $user, Report $report): bool
    {
        return $this->viewAny($user);
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
