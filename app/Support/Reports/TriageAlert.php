<?php

namespace App\Support\Reports;

use App\Enums\ReportState;
use App\Enums\WorkspaceRole;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportNeedsTriage;
use App\Support\Tenancy\Tenancy;

/**
 * Tells the people who triage a project that a report is waiting for them.
 *
 * The recipients are the project's own list (settings → Triage emails), or the
 * workspace's owners and admins when nobody has been named, so a project nobody has
 * configured still reaches somebody rather than sitting unseen. Only staff, whoever is
 * on the list: a client has no inbox. Honours the person's email preference, and sends
 * once per bug — a repeat of a report already waiting is the same row in Triage.
 */
final class TriageAlert
{
    public static function send(Report $report): void
    {
        $workspace = app(Tenancy::class)->currentOrFail();
        $project = $report->project;

        if ($report->state !== ReportState::New) {
            return;
        }

        if ($report->fingerprint !== null && Report::awaitingTriage()
            ->where('project_id', $report->project_id)
            ->where('fingerprint', $report->fingerprint)
            ->whereKeyNot($report->id)
            ->exists()) {
            return;
        }

        $named = $project->triageRecipientIds();

        $recipients = User::query()
            ->whereHas('workspaces', fn ($w) => $w->whereKey($workspace->id)
                ->whereIn('workspace_user.role', $named === []
                    ? [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value]
                    : [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value, WorkspaceRole::Member->value]))
            ->when($named !== [], fn ($q) => $q->whereIn('users.id', $named))
            ->get()
            ->filter(fn (User $user) => $user->wantsEmail());

        $notification = new ReportNeedsTriage(
            (string) $report->title,
            (string) $project->name,
            workspace_url($workspace->slug, 'inbox'),
        );

        foreach ($recipients as $user) {
            $user->notify($notification);
        }
    }
}
