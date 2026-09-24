<?php

namespace App\Actions;

use App\Enums\ClientAudience;
use App\Enums\IssueEventType;
use App\Enums\NotificationReason;
use App\Enums\StatusCategory;
use App\Enums\WatchReason;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a partial update and writes one activity event per field that actually
 * changed. Inline edits from the issue list go through here too, so the feed stays
 * complete no matter which surface made the change.
 */
class UpdateIssue
{
    public function __construct(
        private Notifier $notifier,
        private \App\Support\CustomFields\FieldValues $fields,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Issue $issue, array $attributes, ?User $actor = null): Issue
    {
        // Validated outside the transaction, and only when the caller actually sent
        // them: a bulk status change must not fail because some issue's project has
        // a required field that was never filled in. Required is enforced where a
        // person is filling the form in, not where a status is being dragged.
        $customFields = array_key_exists('custom_fields', $attributes)
            ? $this->fields->validate(
                $issue->loadMissing('project')->project,
                $attributes['custom_fields'] ?? [],
                enforceRequired: false,
                // A PATCH changes what it names and leaves the rest alone.
                partial: true,
            )
            : null;

        return DB::transaction(function () use ($issue, $attributes, $actor, $customFields) {
            // loadMissing, not a plain read: a bulk update hands us issues without
            // their status, and strict mode turns that into an exception rather than
            // a quiet extra query.
            $wasOpen = $issue->loadMissing('status')->isOpen();
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
                    // Handled together after the loop: one entry for one change of plan.
                    'start_on', 'due_on' => null,
                    'version_id' => $this->version($issue, $value === null ? null : (int) $value, $actor),
                    // Not an event: pinning is how the board is laid out, not news
                    // about the issue.
                    'board_pinned' => $issue->board_pinned_at = filter_var($value, FILTER_VALIDATE_BOOL) ? ($issue->board_pinned_at ?? now()) : null,
                    default => null,
                };
            }

            if (array_key_exists('start_on', $attributes) || array_key_exists('due_on', $attributes)) {
                $this->dates($issue, $attributes, $actor);
            }

            // After the loop, and as one change: the audience and the people named in
            // it arrive together from the sidebar, and are only meaningful together.
            if (array_key_exists('client_audience', $attributes) || array_key_exists('client_share_ids', $attributes)) {
                $this->audience($issue, $attributes, $actor);
            }

            $issue->save();

            if ($customFields !== null) {
                $this->fields->store($issue, $customFields);
            }

            $fresh = $issue->refresh()->load(['status', 'project', 'assignee']);

            // Closed is its own event as well as an update: "tell me when something
            // ships" is a different subscription from "tell me when anything moves".
            \App\Support\Webhooks\Webhooks::issue(\App\Enums\WebhookEvent::IssueUpdated, $fresh);
            \App\Support\Chat\ChatNotifications::issue(\App\Enums\WebhookEvent::IssueUpdated, $fresh);

            if ($wasOpen && ! $fresh->isOpen()) {
                \App\Support\Webhooks\Webhooks::issue(\App\Enums\WebhookEvent::IssueClosed, $fresh);
                \App\Support\Chat\ChatNotifications::issue(\App\Enums\WebhookEvent::IssueClosed, $fresh);
            }

            return $fresh;
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

        // Client-visible: where their issue has got to is the first thing a client
        // wants to know, and the thread should say so rather than leave them to
        // notice the sidebar changed.
        $issue->recordEvent(IssueEventType::StatusChanged, [
            'from' => ['name' => $from->name, 'category' => $from->category->value],
            'to' => ['name' => $to->name, 'category' => $to->category->value],
        ], $actor, isInternal: false);

        $issue->status_id = $to->id;

        $this->notifier->watchers($issue, NotificationReason::StatusChanged, $actor, [
            'from' => $from->name,
            'to' => $to->name,
        ]);

        $this->applyClosure($issue, $from->category, $to->category, $actor);
        $this->followWait($issue, $from, $to);
    }

    /**
     * Move an issue on behalf of the client conversation: one event of the caller's
     * choosing instead of a plain status change, and no watcher notifications,
     * because the caller decides who hears about a reply and in what words.
     *
     * @param  array<string, mixed>  $data
     */
    public function moveTo(Issue $issue, Status $to, ?User $actor, IssueEventType $as, array $data = []): void
    {
        $from = $issue->loadMissing('status')->status;

        abort_unless($to->project_id === $issue->project_id, 422, 'Status belongs to another project.');

        if ($from->id !== $to->id) {
            $issue->recordEvent($as, [
                'from' => ['name' => $from->name, 'category' => $from->category->value],
                'to' => ['name' => $to->name, 'category' => $to->category->value],
                ...$data,
            ], $actor, isInternal: false);

            $issue->status_id = $to->id;
            $this->applyClosure($issue, $from->category, $to->category, $actor);
        }

        $issue->save();
        $issue->unsetRelation('status');

        $fresh = $issue->refresh()->load(['status', 'project', 'assignee']);
        \App\Support\Webhooks\Webhooks::issue(\App\Enums\WebhookEvent::IssueUpdated, $fresh);

        if ($from->category->isOpen() && ! $fresh->isOpen()) {
            \App\Support\Webhooks\Webhooks::issue(\App\Enums\WebhookEvent::IssueClosed, $fresh);
        }
    }

    /**
     * Whose turn it is follows the status, however it was moved.
     *
     * Moved into the awaiting-client status by hand, the wait starts as if the team
     * had used Reply & await client; moved out of it, the wait is over, so nothing
     * reminds a client about an issue that is no longer waiting on them.
     */
    private function followWait(Issue $issue, Status $from, Status $to): void
    {
        if ($to->is_awaiting_client && ! $from->is_awaiting_client) {
            $issue->status_before_waiting_id = $from->id;
            $issue->awaiting_client_since = now();
            $issue->client_reminded_at = null;
            $issue->auto_closed_at = null;
        } elseif ($from->is_awaiting_client && ! $to->is_awaiting_client) {
            $issue->status_before_waiting_id = null;
            $issue->awaiting_client_since = null;
            $issue->client_reminded_at = null;
        }
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
            $issue->recordEvent(IssueEventType::Closed, ['category' => $to->value], $actor, isInternal: false);

            return;
        }

        if (! $from->isOpen() && $to->isOpen()) {
            $issue->closed_at = null;
            $issue->resolved_at = null;
            $issue->recordEvent(IssueEventType::Reopened, [], $actor, isInternal: false);
        }
    }

    private function assignee(Issue $issue, ?int $userId, ?User $actor): void
    {
        if ($issue->assignee_id === $userId) {
            return;
        }

        $previous = $issue->assignee;
        $next = $userId ? User::find($userId) : null;

        \App\Support\Issues\Assignable::ensure($next, $issue->workspace);

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

    /**
     * Put an issue in a release, or take it out of one.
     *
     * Refused if the version belongs to another project: "2.4.1" is a release of one
     * thing, and an issue on the marketing site has no business being in the mobile
     * app's release notes.
     */
    private function version(Issue $issue, ?int $versionId, ?User $actor): void
    {
        if ($issue->version_id === $versionId) {
            return;
        }

        $next = $versionId === null ? null : \App\Models\Version::find($versionId);

        if ($versionId !== null && $next?->project_id !== $issue->project_id) {
            throw ValidationException::withMessages([
                'version_id' => 'That release belongs to another project.',
            ]);
        }

        $issue->recordEvent(IssueEventType::VersionChanged, [
            'from' => $issue->version?->name,
            'to' => $next?->name,
        ], $actor);

        $issue->version_id = $next?->id;
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

    /**
     * Which clients see a client-visible issue: the tier default, every client on
     * the project, or the default plus named clients.
     *
     * Only clients who hold this issue's project can be named. The scope would give
     * anybody else nothing anyway, but a share that does nothing is a screen that
     * lies about who can see the issue.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function audience(Issue $issue, array $attributes, ?User $actor): void
    {
        $from = $issue->client_audience;
        $to = array_key_exists('client_audience', $attributes)
            ? ClientAudience::from($attributes['client_audience'])
            : $from;

        $current = $issue->clientShares()->orderBy('users.id')->pluck('users.id')->all();

        $wanted = $to === ClientAudience::Specific
            ? collect($attributes['client_share_ids'] ?? $current)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()
            : [];

        if ($to === ClientAudience::Specific) {
            if ($wanted === []) {
                throw ValidationException::withMessages(['client_share_ids' => 'Choose at least one client.']);
            }

            $eligible = $issue->loadMissing('project')->project->clients()
                ->whereIn('users.id', $wanted)
                ->whereHas('workspaces', fn ($w) => $w
                    ->where('workspaces.id', $issue->workspace_id)
                    ->where('workspace_user.role', \App\Enums\WorkspaceRole::Client->value))
                ->pluck('users.id')
                ->all();

            if (array_diff($wanted, $eligible) !== []) {
                throw ValidationException::withMessages([
                    'client_share_ids' => 'Only clients who can see this project can be chosen.',
                ]);
            }
        }

        if ($from === $to && $current === $wanted) {
            return;
        }

        $issue->clientShares()->sync(collect($wanted)->mapWithKeys(fn (int $id) => [
            $id => ['shared_by_id' => $actor?->id, 'created_at' => now()],
        ])->all());

        $issue->client_audience = $to;

        // Internal, as every event is by default — and this one must be: it names
        // clients, and one client learning another's name is a leak.
        $issue->recordEvent(IssueEventType::AudienceChanged, [
            'from' => $from->value,
            'to' => $to->value,
            'clients' => User::whereIn('id', $wanted)->orderBy('name')->pluck('name')->all(),
        ], $actor);
    }

    /**
     * The plan moved. Recorded, internally, because somebody's dates being changed
     * under them is worth being able to see — and the timeline names whoever did it
     * when it refuses a drag made on top of theirs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dates(Issue $issue, array $attributes, ?User $actor): void
    {
        $from = ['start_on' => $issue->start_on?->toDateString(), 'due_on' => $issue->due_on?->toDateString()];

        $issue->fill(array_intersect_key($attributes, array_flip(['start_on', 'due_on'])));

        $to = ['start_on' => $issue->start_on?->toDateString(), 'due_on' => $issue->due_on?->toDateString()];

        if ($from !== $to) {
            $issue->recordEvent(IssueEventType::DatesChanged, ['from' => $from, 'to' => $to], $actor);
        }
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
