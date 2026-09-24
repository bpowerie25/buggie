<?php

namespace App\Support\Issues;

use App\Enums\ClientAudience;
use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who among the clients can actually see an issue, for staff.
 *
 * The badge used to say "Client can see this" whenever an issue was client-visible,
 * which was true of a client manager and false of everybody else on the project.
 * This asks the scope itself about each client on the project, so the answer is
 * whatever the rule is, not a description of it that can drift.
 *
 * Staff only. It names clients, and one client learning another's name is a leak.
 */
class ClientAudienceSummary
{
    /** Clients on the issue's project, in name order. */
    public function candidates(Issue $issue): Collection
    {
        return $issue->loadMissing('project')->project->clients()
            ->whereHas('workspaces', fn ($w) => $w
                ->where('workspaces.id', $issue->workspace_id)
                ->where('workspace_user.role', WorkspaceRole::Client->value))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
    }

    /** @return Collection<int, User> */
    public function visibleTo(Issue $issue): Collection
    {
        return $this->candidates($issue)
            ->filter(fn (User $client) => Issue::query()->whereKey($issue->getKey())->visibleToClient($client)->exists())
            ->values();
    }

    public function label(Issue $issue): string
    {
        if ($issue->visibility !== IssueVisibility::Client) {
            return 'Internal only';
        }

        if ($issue->client_audience === ClientAudience::Project) {
            return "All {$issue->project->name} clients";
        }

        $names = $this->visibleTo($issue)->pluck('name');

        return $names->isEmpty()
            ? 'No client can see this yet'
            : 'Visible to: '.$names->join(', ');
    }
}
