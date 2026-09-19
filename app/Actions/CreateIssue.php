<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\WatchReason;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Enums\NotificationReason;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;

class CreateIssue
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  array{
     *     title: string,
     *     description?: array<string, mixed>|null,
     *     type?: string,
     *     status_id?: int|null,
     *     priority?: int,
     *     assignee_id?: int|null,
     *     visibility?: string,
     *     labels?: array<int, int>,
     * }  $attributes
     */
    public function handle(Project $project, array $attributes, ?User $reporter = null): Issue
    {
        // A client filing an issue is filing it about their own project, so it stays
        // visible to them — otherwise they lose sight of it the moment it is created
        // — and they do not get to hand work to a member of the team.
        //
        // This lives in the action rather than the controller so that every way in
        // gets it: the screens, the API, and whatever comes next. It used to live in
        // one controller, and the API promptly disagreed with it.
        if ($reporter !== null && ! ($reporter->membershipIn($project->workspace)?->isStaff() ?? false)) {
            $attributes['visibility'] = \App\Enums\IssueVisibility::Client->value;
            $attributes['assignee_id'] = null;
        }

        return DB::transaction(function () use ($project, $attributes, $reporter) {
            $status = $attributes['status_id'] ?? $project->defaultStatus()?->id;

            abort_if($status === null, 422, 'This project has no statuses configured.');

            $description = $attributes['description'] ?? null;
            $number = $project->nextIssueNumber();

            $issue = new Issue([
                'project_id' => $project->id,
                'title' => $attributes['title'],
                'description' => $description,
                'description_text' => TiptapDocument::toPlainText($description),
                'type' => $attributes['type'] ?? 'bug',
                'status_id' => $status,
                'priority' => $attributes['priority'] ?? 0,
                'assignee_id' => $attributes['assignee_id'] ?? $project->default_assignee_id,
                'reporter_id' => $reporter?->id,
                'visibility' => $attributes['visibility'] ?? 'internal',
            ]);

            // number/key are not fillable: they are the project's to hand out. They
            // must be set before the insert, not after — both columns are NOT NULL.
            $issue->forceFill([
                'number' => $number,
                'key' => "{$project->key}-{$number}",
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                // Set explicitly rather than left to the column default: callers hold
                // this instance and increment it, and a default only the database
                // knows about reads back as null.
                'occurrence_count' => 1,
            ])->save();

            if ($labels = $attributes['labels'] ?? []) {
                $issue->labels()->sync($labels);
            }

            $issue->recordEvent(IssueEventType::Created, [], $reporter);

            $issue->watch($reporter, WatchReason::Reported);
            $issue->watch($issue->assignee, WatchReason::Assigned);

            foreach (TiptapDocument::mentionedUserIds($description) as $id) {
                $issue->watch(User::find($id), WatchReason::Mentioned);
            }

            if ($issue->assignee) {
                $this->notifier->record($issue->assignee, $issue, NotificationReason::Assigned, $reporter);
            }

            \App\Support\Webhooks\Webhooks::issue(
                \App\Enums\WebhookEvent::IssueCreated,
                $issue->load(['status', 'project', 'assignee']),
            );

            return $issue;
        });
    }
}
