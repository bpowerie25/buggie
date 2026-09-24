<?php

namespace App\Console\Commands;

use App\Models\Issue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issues whose assignee is a client, from before only staff could be assigned.
 *
 * Reports and changes nothing: which of the team should own each one is a decision,
 * and unassigning quietly would lose the only record that somebody meant to ask the
 * client something. Also lists projects whose default assignee is a client, which
 * would have handed that client every new issue.
 */
class ReportClientAssignees extends Command
{
    protected $signature = 'buggie:client-assignees';

    protected $description = 'List issues assigned to a client, and projects defaulting to one (reports only)';

    public function handle(): int
    {
        $isClient = fn (string $column) => fn ($q) => $q->selectRaw('1')->from('workspace_user')
            ->whereColumn('workspace_user.user_id', $column)
            ->whereColumn('workspace_user.workspace_id', DB::raw(explode('.', $column)[0].'.workspace_id'))
            ->where('workspace_user.role', 'client');

        // Across every workspace on purpose: this is an operator's audit.
        $issues = Issue::query()->acrossAllWorkspaces()->withTrashed()
            ->whereExists($isClient('issues.assignee_id'))
            ->with(['assignee:id,name,email', 'project:id,key', 'workspace:id,slug'])
            ->orderBy('workspace_id')->orderBy('key')
            ->get();

        $this->line('<info>Issues assigned to a client</info>');

        $issues->isEmpty()
            ? $this->line('  None.')
            : $this->table(['Workspace', 'Issue', 'Title', 'Assignee', 'Updated', 'Deleted'], $issues->map(fn (Issue $issue) => [
                $issue->workspace?->slug,
                $issue->key,
                mb_strimwidth($issue->title, 0, 50, '…'),
                "{$issue->assignee?->name} <{$issue->assignee?->email}>",
                $issue->updated_at?->toDateString(),
                $issue->deleted_at?->toDateString() ?? '',
            ])->all());

        $projects = DB::table('projects')
            ->whereExists($isClient('projects.default_assignee_id'))
            ->join('users', 'users.id', '=', 'projects.default_assignee_id')
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->get(['workspaces.slug', 'projects.key', 'users.name', 'users.email']);

        $this->newLine();
        $this->line('<info>Projects whose default assignee is a client</info>');

        $projects->isEmpty()
            ? $this->line('  None.')
            : $this->table(['Workspace', 'Project', 'Default assignee'], $projects->map(fn ($p) => [
                $p->slug, $p->key, "{$p->name} <{$p->email}>",
            ])->all());

        $this->newLine();
        $this->comment('Nothing was changed. New issues no longer fall back to a client default assignee.');

        return self::SUCCESS;
    }
}
