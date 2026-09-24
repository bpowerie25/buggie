<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\WatchReason;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Enums\NotificationReason;
use App\Support\Notifications\Notifier;
use App\Support\CustomFields\FieldValues;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;

class CreateIssue
{
    public function __construct(
        private Notifier $notifier,
        private FieldValues $fields,
    ) {}

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
     *     custom_fields?: array<string, mixed>,
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
        $raisedByClient = $reporter !== null
            && ! ($reporter->membershipIn($project->workspace)?->isStaff() ?? false);

        if ($raisedByClient) {
            $attributes['visibility'] = \App\Enums\IssueVisibility::Client->value;
            $attributes['assignee_id'] = null;

            // Nor do they choose where it starts. The form never offered them a
            // status, but the request would have taken one — straight into "Done",
            // or into the team's ready-to-work queue with nobody having looked.
            unset($attributes['status_id']);
        }

        // Staff only, whoever is asking and however they got here. The project's
        // default assignee is checked too: a client there would be handed every new
        // issue silently, and is skipped rather than refused, because the person
        // filing did not choose it.
        if (isset($attributes['assignee_id'])) {
            \App\Support\Issues\Assignable::ensure(User::find($attributes['assignee_id']), $project->workspace);
        }

        $fallbackAssignee = $project->defaultAssignee;

        if ($fallbackAssignee !== null && ! \App\Support\Issues\Assignable::isAssignable($fallbackAssignee, $project->workspace)) {
            $fallbackAssignee = null;
        }

        // Validated before the transaction opens rather than inside it: a rejected
        // value should never have consumed an issue number, and the numbers are
        // handed out by the project rather than by a sequence, so a rolled-back
        // insert leaves a visible gap.
        $customFields = $this->fields->validate($project, $attributes['custom_fields'] ?? []);

        return DB::transaction(function () use ($project, $attributes, $reporter, $customFields, $raisedByClient, $fallbackAssignee) {
            $status = $attributes['status_id'] ?? $this->startingStatus($project, $raisedByClient)?->id;

            abort_if($status === null, 422, 'This project has no statuses configured.');

            // Validated against the workspace by the request; this is the project.
            // A status from a sibling project would put the issue in a workflow its
            // own project does not have.
            abort_unless(
                $project->statuses()->whereKey($status)->exists(),
                422,
                'That status belongs to another project.',
            );

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
                'assignee_id' => array_key_exists('assignee_id', $attributes) && $attributes['assignee_id'] !== null
                    ? $attributes['assignee_id']
                    : $fallbackAssignee?->id,
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
                // The bottom of the board. New work is placed deliberately rather
                // than barging into the middle of an order somebody chose — and the
                // list, which is the default view, still leads with priority.
                'board_rank' => \App\Support\Issues\BoardRank::forNewIssue($project->workspace_id),
            ])->save();

            if ($labels = $attributes['labels'] ?? []) {
                $issue->labels()->sync($labels);
            }

            $this->fields->store($issue, $customFields);

            // Client-visible: "created this issue" is how a client's thread begins.
            $issue->recordEvent(IssueEventType::Created, [], $reporter, isInternal: false);

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

            \App\Support\Chat\ChatNotifications::issue(
                \App\Enums\WebhookEvent::IssueCreated,
                $issue,
            );

            return $issue;
        });
    }

    /**
     * A client's issue waits in "New" until somebody on the team has looked at it,
     * and is listed on the Triage screen meanwhile. Staff are triaging as they file,
     * so theirs start in the project's default — and they can pick another.
     */
    private function startingStatus(Project $project, bool $raisedByClient): ?\App\Models\Status
    {
        return ($raisedByClient ? $project->triageStatus() : null) ?? $project->defaultStatus();
    }
}
