<?php

namespace App\Actions;

use App\Enums\ClientAudience;
use App\Enums\IssueEventType;
use App\Enums\IssueVisibility;
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
                    'start_on' => $issue->fill(['start_on' => $value]),
                    'due_on' => $issue->fill(['due_on' => $value]),
                    'version_id' => $this->version($issue, $value === null ? null : (int) $value, $actor),
                    default => null,
                };
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

        if ($next !== null) {
            $this->assignableToClient($issue, $next, $actor);
        }

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
     * Assigning to a client makes the issue visible to them, and refuses outright if
     * they have no grant on its project.
     *
     * Asking a client a question is a real workflow — "which browser was it?", "can
     * you confirm this is fixed?" — and it was half-built: a client could be assigned,
     * and would be notified, but IssuePolicy still required the issue to be marked
     * client-visible, so following the notification gave them a 404. Being told about
     * something you then cannot open is worse than not being told.
     *
     * The visibility change is recorded as its own event rather than done quietly.
     * Who can see an issue is the most consequential thing about it in this product,
     * and it should never change without the activity feed saying so.
     */
    private function assignableToClient(Issue $issue, User $assignee, ?User $actor): void
    {
        if ($assignee->membershipIn($issue->workspace)?->isStaff() ?? true) {
            return;
        }

        if (! $assignee->projects()->whereKey($issue->project_id)->exists()) {
            throw ValidationException::withMessages([
                'assignee_id' => "{$assignee->name} does not have access to this project, so they cannot be asked about it.",
            ]);
        }

        if ($issue->visibility !== IssueVisibility::Client) {
            $issue->visibility = IssueVisibility::Client;

            // Not internal: the client should see why they can suddenly see this.
            $issue->recordEvent(IssueEventType::VisibilityChanged, [
                'to' => IssueVisibility::Client->value,
                'because' => 'assigned to a client',
            ], $actor, isInternal: false);
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
