<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

/**
 * What strangers may already have set up, from before sign-up was closed.
 *
 * Lists accounts that belong to no workspace, and workspaces whose owner is not an
 * operator. Neither is necessarily wrong — a workspace made by an operator and handed
 * over, somebody removed from a workspace who kept their account — so this reports
 * and never deletes. Deciding is a person's job.
 *
 * "Created by" is read from the owner, because that is who created a workspace: the
 * action makes its creator the owner, and nothing in the application transfers it.
 */
class AuditSignups extends Command
{
    protected $signature = 'buggie:audit-signups';

    protected $description = 'List accounts in no workspace, and workspaces no operator owns (reports only)';

    public function handle(): int
    {
        $loners = User::query()
            ->whereDoesntHave('workspaces')
            ->orderBy('created_at')
            ->get();

        $this->line('<info>Accounts that belong to no workspace</info>');

        $loners->isEmpty()
            ? $this->line('  None.')
            : $this->table(['Id', 'Email', 'Name', 'Created', 'Operator'], $loners->map(fn (User $user) => [
                $user->id,
                $user->email,
                $user->name,
                $user->created_at?->toDateTimeString(),
                $this->isOperator($user) ? 'yes' : '',
            ])->all());

        // Soft-deleted ones too: a workspace somebody deleted still holds whatever
        // was uploaded to it until it is purged.
        $workspaces = Workspace::withTrashed()
            ->with('owner')
            ->withCount('members')
            ->orderBy('created_at')
            ->get()
            ->reject(fn (Workspace $workspace) => $workspace->owner !== null && $this->isOperator($workspace->owner));

        $this->newLine();
        $this->line('<info>Workspaces not owned by an operator</info>');

        $workspaces->isEmpty()
            ? $this->line('  None.')
            : $this->table(['Slug', 'Name', 'Owner', 'Members', 'Created', 'Deleted'], $workspaces->map(fn (Workspace $w) => [
                $w->slug,
                $w->name,
                $w->owner?->email ?? '(no account)',
                $w->members_count,
                $w->created_at?->toDateTimeString(),
                $w->deleted_at?->toDateTimeString() ?? '',
            ])->all());

        $this->newLine();
        $this->comment('Nothing was changed. Workspaces you invited people into will be listed here too if an operator does not own them.');

        return self::SUCCESS;
    }

    private function isOperator(User $user): bool
    {
        return Gate::forUser($user)->allows('operate');
    }
}
