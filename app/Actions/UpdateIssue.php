<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\StatusCategory;
use App\Enums\WatchReason;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Enums\NotificationReason;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial update and writes one activity event per field that actually
 * changed. Inline edits from the issue list go through here too, so the feed stays
 * complete no matter which surface made the change.
 */
class UpdateIssue
{
    public function __construct(private Notifier $notifier) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Issue $issue, array $attributes, ?User $actor = null): Issue
    {
        return DB::transaction(function () use ($issue, $attributes, $actor) {
            foreach ($attributes as $field => $value) {
                match ($field) {
                    'title' => $this->title($issue, $value, $actor),
                    'description' => $this->description($issue, $value),
                    'status_id' => $this->status($issue, (int) $value, $actor),
                    'assignee_id' => $this->assignee($issue, $value, $actor),
                    'priority' => $this->priority($issue, (int) $value, $actor),
                    'type' => $this->simple($issue, 'type', $value, IssueEventType::TypeChanged, $actor),
                    'visibility' => $this->simple($issue, 'visibility', $value, IssueEventType::VisibilityChanged, $actor),
                    'labels' => $this->labels($issue, $value, $actor),
                    'due_on' => $issue->fill(['due_on' => $value]),
                    default => null,
                };
            }

            $issue->save();

            return $issue->refresh();
        });
    }

    private function title(Issue $issue, string $value, ?User $actor): void
    {
        if ($issue->title === $value) {
            return;
        }

        $issue->recordEvent(
            IssueEventType::TitleChanged,
            ['from' => $issue->title, 'to' => $value],
            $actor,
        );

        $issue->title = $value;
    }

    /** @param array<string, mixed>|null $value */
    private function description(Issue $issue, ?array $value): void
    {
        // Body edits are not events; the feed would fill with noise.
        $issue->description = $value;
        $issue->description_text = TiptapDocument::toPlainText($value);
    }

    private function status(Issue $issue, int $statusId, ?User $actor): void
    {
        if ($issue->status_id === $statusId) {
            return;
        }

        $from = $issue->status;
        $to = Status::findOrFail($statusId);

        // A status from another project would silently move the issue's workflow.
        abort_unless($to->project_id === $issue->project_id, 422, 'Status belongs to another project.');

        $issue->recordEvent(IssueEventType::StatusChanged, [
            'from' => ['name' => $from->name, 'category' => $from->category->value],
            'to' => ['name' => $to->name, 'category' => $to->category->value],
        ], $actor);

        $issue->status_id = $to->id;

        $this->notifier->watchers($issue, NotificationReason::StatusChanged, $actor, [
            'from' => $from->name,
            'to' => $to->name,
        ]);

        $this->applyClosure($issue, $from->category, $to->category, $actor);
    }

    /** Timestamps and reopen/close events follow the category, never the status name. */
    private function applyClosure(
        Issue $issue,
        StatusCategory $from,
        StatusCategory $to,
        ?User $actor,
    ): void {
        if ($from->isOpen() && ! $to->isOpen()) {
            $issue->closed_at = now();
            $issue->resolved_at = $to === StatusCategory::Done ? now() : null;
            $issue->recordEvent(IssueEventType::Closed, ['category' => $to->value], $actor);

            return;
        }

        if (! $from->isOpen() && $to->isOpen()) {
            $issue->closed_at = null;
            $issue->resolved_at = null;
            $issue->recordEvent(IssueEventType::Reopened, [], $actor);
        }
    }

    private function assignee(Issue $issue, ?int $userId, ?User $actor): void
    {
        if ($issue->assignee_id === $userId) {
            return;
        }

        $previous = $issue->assignee;
        $next = $userId ? User::find($userId) : null;

        $issue->recordEvent(
            $next ? IssueEventType::Assigned : IssueEventType::Unassigned,
            ['from' => $previous?->name, 'to' => $next?->name],
            $actor,
        );

        $issue->assignee_id = $next?->id;
        $issue->watch($next, WatchReason::Assigned);

        if ($next !== null) {
            $this->notifier->record($next, $issue, NotificationReason::Assigned, $actor);
        }
    }

    private function priority(Issue $issue, int $priority, ?User $actor): void
    {
        if ($issue->priority->value === $priority) {
            return;
        }

        $issue->recordEvent(IssueEventType::PriorityChanged, [
            'from' => $issue->priority->value,
            'to' => $priority,
        ], $actor);

        $issue->priority = $priority;
    }

    private function simple(
        Issue $issue,
        string $field,
        mixed $value,
        IssueEventType $type,
        ?User $actor,
    ): void {
        $current = $issue->{$field};
        $currentValue = $current instanceof \BackedEnum ? $current->value : $current;

        if ($currentValue === $value) {
            return;
        }

        $issue->recordEvent($type, ['from' => $currentValue, 'to' => $value], $actor);
        $issue->{$field} = $value;
    }

    /** @param array<int, int> $labelIds */
    private function labels(Issue $issue, array $labelIds, ?User $actor): void
    {
        $before = $issue->labels()->pluck('labels.id', 'labels.name');
        $changes = $issue->labels()->sync($labelIds);

        if (! $changes['attached'] && ! $changes['detached']) {
            return;
        }

        $after = $issue->labels()->get()->pluck('name', 'id');

        foreach ($changes['attached'] as $id) {
            $issue->recordEvent(IssueEventType::LabelAdded, ['name' => $after[$id] ?? null], $actor);
        }

        foreach ($changes['detached'] as $id) {
            $issue->recordEvent(
                IssueEventType::LabelRemoved,
                ['name' => $before->flip()[$id] ?? null],
                $actor,
            );
        }
    }
}
