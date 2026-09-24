<?php

namespace App\Http\Controllers;

use App\Actions\CreateIssue;
use App\Actions\UpdateIssue;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use App\Support\Issues\AuthorLabel;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    public function __construct(
        private Tenancy $tenancy,
        private IssueQueryFilter $filter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Issue::class);

        $query = IssueQuery::parse($request->string('q')->toString());

        return Inertia::render('issues/index', [
            // Plain closures: Inertia evaluates only the props a partial reload asks
            // for, so an inline edit re-runs the issue query and nothing else.
            'issues' => fn () => $this->issues($request, $query, $this->layout($request)),
            'query' => $query->toArray(),
            'layout' => $this->layout($request),
            'groupBy' => $this->groupBy($request),
            'facets' => fn () => $this->facets(),
        ]);
    }

    private function layout(Request $request): string
    {
        return $request->string('layout')->toString() === 'board' ? 'board' : 'list';
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function issues(Request $request, IssueQuery $query, string $layout = 'list'): \Illuminate\Support\Collection
    {
        $builder = Issue::query()->unless(
            $this->isStaff($request->user()),
            fn (Builder $q) => $q->visibleToClient($request->user()),
        );

        return $this->filter->apply($builder, $query, $request->user())
            ->with([
                'status:id,name,category,color,position',
                'assignee:id,name',
                'labels:id,name,color',
                'project:id,key,slug,name',
            ])
            /*
             * The board is in the order somebody dragged it into; the list is in the
             * order that answers "what is most urgent".
             *
             * Two different questions, so two different sorts. Making the list follow
             * the board's manual order would mean a card dragged down the board
             * quietly leaving the top of everybody's list.
             */
            ->when(
                $layout === 'board',
                fn (Builder $q) => $q->orderByRaw('board_rank NULLS LAST, id'),
                fn (Builder $q) => $q->orderByRaw('priority DESC, updated_at DESC'),
            )
            // A hard ceiling; the list virtualises but the payload should stay sane.
            ->limit(1000)
            ->get()
            ->map($this->summary(...))
            ->values();
    }

    private function groupBy(Request $request): string
    {
        $value = $request->string('group')->toString();

        return in_array($value, ['status', 'assignee', 'priority', 'project'], true)
            ? $value
            : 'status';
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Issue::class);

        $project = $request->filled('project')
            ? Project::where('slug', $request->string('project'))->firstOrFail()
            : Project::active()->orderBy('name')->firstOrFail();

        return Inertia::render('issues/create', [
            'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name, 'slug' => $project->slug],
            'facets' => $this->facets(),
            'statuses' => $this->statusesFor($project),
            // A client filing an issue is only offered the fields they can see; the
            // internal ones are not rendered blank-and-disabled, they are absent.
            'customFields' => app(\App\Support\CustomFields\FieldValues::class)
                ->definitions($project, clientOnly: ! $this->isStaff($request->user()))
                ->map(fn ($field) => [
                    'key' => $field->key,
                    'name' => $field->name,
                    'type' => $field->type->value,
                    'options' => $field->options ?? [],
                    'required' => $field->required,
                ])->values(),
        ]);
    }

    public function store(StoreIssueRequest $request, CreateIssue $action): RedirectResponse
    {
        $this->authorize('create', Issue::class);

        $project = Project::findOrFail($request->integer('project_id'));

        // Client visibility is forced inside the action, so every entry point gets
        // it rather than only this one.
        $issue = $action->handle($project, $request->validated(), $request->user());

        return redirect()
            ->route('issues.show', $issue)
            ->with('success', "{$issue->key} created.");
    }

    public function show(Issue $issue): Response
    {
        $this->authorize('view', $issue);

        $issue->load([
            'status', 'project', 'assignee', 'reporter', 'labels', 'version',
            'parent:id,key,title',
            'children:id,parent_id,key,title,status_id',
            'children.status:id,name,color,category',
            'attachments.uploadedBy:id,name',
            'watchers:id,name',
            'relations.relatedIssue:id,key,title,status_id',
            'relations.relatedIssue.status:id,name,category,color',
        ]);

        $staff = $this->isStaff(request()->user());
        $workspace = $this->tenancy->currentOrFail();
        $viewer = request()->user();

        // Somebody on the team has looked, so "client replied" comes down.
        if ($staff) {
            app(\App\Support\Issues\ClientConversation::class)->seenByStaff($issue);
        }

        // What a client may know about other issues: only those they could open. A
        // related or child issue's title is otherwise internal work in the payload.
        $visibleKeys = $staff ? null : Issue::query()
            ->whereIn('id', collect([$issue->parent?->id])
                ->merge($issue->children->pluck('id'))
                ->merge($issue->relations->pluck('relatedIssue.id'))
                ->filter()->all())
            ->visibleToClient($viewer)
            ->pluck('id')
            ->all();

        $canSee = fn (?int $id) => $id !== null && ($staff || in_array($id, $visibleKeys, true));

        // Who wrote something, for whoever is reading it: see AuthorLabel.
        $author = fn (?User $user, ?string $fallback = null) => [
            'id' => $staff || $user === null || AuthorLabel::role($user, $workspace) === 'client' ? $user?->id : null,
            'name' => AuthorLabel::for($user, $workspace, $staff, $fallback),
            'role' => AuthorLabel::role($user, $workspace),
        ];

        $audience = app(\App\Support\Issues\ClientAudienceSummary::class);
        $audienceLabel = $staff ? $audience->label($issue) : null;

        return Inertia::render('issues/show', [
            // Filtered in the query, not in the template: a field marked internal
            // must not reach a client's browser at all. Rendering conditionally
            // would still put "Internal estimate: 3 days" in the page source.
            'customFields' => app(\App\Support\CustomFields\FieldValues::class)
                ->forIssue($issue, clientOnly: ! $staff),

            // One level, so this is a parent or a list of children, never both.
            'parent' => $canSee($issue->parent?->id) ? $issue->parent->only(['key', 'title']) : null,
            'children' => $issue->children
                ->filter(fn (\App\Models\Issue $child) => $canSee($child->id))
                ->map(fn (\App\Models\Issue $child) => [
                    'key' => $child->key,
                    'title' => $child->title,
                    'status' => $child->status?->name,
                    'open' => $child->status?->category->isOpen() ?? true,
                ])->values(),

            /*
             * Time, for staff only and absent otherwise.
             *
             * Not a prop the page hides: a client's payload does not contain the
             * hours at all. How long something took is an input to an invoice, not
             * a status update.
             */
            'time' => $staff ? [
                'entries' => $issue->timeEntries()->with('user:id,name')->orderByDesc('spent_on')
                    ->orderByDesc('id')->get()
                    ->map(fn (\App\Models\TimeEntry $entry) => [
                        'id' => $entry->id,
                        'duration' => $entry->formatted(),
                        'minutes' => $entry->minutes,
                        'spent_on' => $entry->spent_on->toDateString(),
                        'note' => $entry->note,
                        'billable' => $entry->billable,
                        'user' => $entry->user?->name ?? 'Someone who has left',
                        'can_delete' => request()->user()->can('delete', $entry),
                    ]),
                'total' => \App\Support\Time\Duration::format(
                    $total = (int) $issue->timeEntries()->sum('minutes'),
                ),
                'total_minutes' => $total,
                'estimate' => \App\Support\Time\Duration::format($issue->estimate_minutes),
                'estimate_minutes' => $issue->estimate_minutes,
                // Only meaningful with both numbers, and only interesting when it is
                // over: "you are under your estimate" is not news.
                'over_by' => $issue->estimate_minutes !== null && $total > $issue->estimate_minutes
                    ? \App\Support\Time\Duration::format($total - $issue->estimate_minutes)
                    : null,
                'can_log' => request()->user()->can('create', \App\Models\TimeEntry::class),
            ] : null,
            'issue' => [
                ...$this->summary($issue),
                'description' => $issue->description,
                'reporter' => $issue->reporter === null ? null : $author($issue->reporter),
                // Who sent a widget report and how sure we are. Staff only: it is an
                // address and an assessment, and neither is a client's business.
                'widget_reporter' => $staff && $issue->reporter_identity !== null ? [
                    'identity' => $issue->reporter_identity->value,
                    'label' => $issue->reporter_identity->label(),
                    'name' => $issue->reporter_name,
                    'email' => $issue->reporter_email,
                    'page_url' => is_string($issue->environment['url'] ?? null) ? $issue->environment['url'] : null,
                    'linked' => $issue->reporter !== null && AuthorLabel::role($issue->reporter, $workspace) === 'client',
                    // Who "Link to client" can choose from, the address match first.
                    'candidates' => \App\Support\Reports\ReporterLink::eligible($issue)
                        ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
                        ->sortByDesc(fn ($u) => strcasecmp((string) $u->email, (string) $issue->reporter_email) === 0)
                        ->values()
                        ->map(fn ($u) => [
                            'id' => $u->id,
                            'name' => $u->name,
                            'matches' => strcasecmp((string) $u->email, (string) $issue->reporter_email) === 0,
                        ]),
                ] : null,
                'visibility' => $issue->visibility->value,
                'client_audience' => $issue->client_audience->value,
                // Staff only: both name clients, and a client must not learn who
                // else the agency works with. A client is told only that it is theirs.
                'client_share_ids' => $staff ? $issue->clientShares()->pluck('users.id') : [],
                'audience_label' => $staff
                    ? app(\App\Support\Issues\ClientAudienceSummary::class)->label($issue)
                    : null,
                'start_on' => $issue->start_on?->toDateString(),
                'due_on' => $issue->due_on?->toDateString(),
                'version' => $issue->version?->only(['id', 'name']),
                'created_at' => $issue->created_at->toIso8601String(),
                // A client is shown only whether they themselves watch it: the list
                // can name other clients, and the team speaks as the workspace.
                'watchers' => $staff ? $issue->watchers->map->only(['id', 'name']) : [],
                'watching' => $issue->watchers->contains('id', request()->user()->id),
                'relations' => $issue->relations->filter(fn ($relation) => $canSee($relation->relatedIssue?->id))->values()->map(fn ($relation) => [
                    'id' => $relation->id,
                    'type' => $relation->type->value,
                    'label' => $relation->type->label(),
                    'issue' => [
                        'key' => $relation->relatedIssue->key,
                        'title' => $relation->relatedIssue->title,
                        'status' => $relation->relatedIssue->status->name,
                        'open' => $relation->relatedIssue->status->category->isOpen(),
                    ],
                ]),
            ],
            // Staff only, and not because the browser and route are sensitive: the
            // console and network tables are whatever the customer's application
            // happened to log, and a client should not be reading that about their
            // own users. Clients never see the triage inbox either.
            'diagnostics' => $staff ? $this->diagnostics($issue) : null,
            'relationTypes' => \App\Enums\RelationType::options(),

            // Who can be named in a "specific clients" audience. Staff only.
            'projectClients' => $staff
                ? app(\App\Support\Issues\ClientAudienceSummary::class)->candidates($issue)
                    ->map(fn ($client) => ['id' => $client->id, 'name' => $client->name])
                : [],

            // The releases this issue could belong to: its own project's, and the
            // unreleased ones first, because that is what anybody is choosing between.
            'versions' => \App\Models\Version::where('project_id', $issue->project_id)
                ->inWorkingOrder()
                ->get()
                ->map(fn (\App\Models\Version $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'released' => $v->isReleased(),
                ]),

            // The one place the client visibility plane is enforced for reading.
            'comments' => $issue->comments()
                ->with('author:id,name')
                ->unless($staff, fn ($q) => $q->public())
                ->get()
                ->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $comment->is_internal,
                    // Portal and email replies have no account; the system's own
                    // notes (auto-close) have no author and speak as the workspace.
                    'author' => $author(
                        $comment->author,
                        $comment->source === 'system' ? null : ($comment->author_name ?? $comment->author_email),
                    ),
                    // Who a public comment reaches now, for the badge's hover. Staff
                    // only, and the issue's current audience, not a record of then.
                    'audience' => $staff && ! $comment->is_internal ? $audienceLabel : null,
                    // Microseconds preserved so the merged feed sorts deterministically.
                    'created_at' => $comment->created_at->format('Y-m-d\TH:i:s.uP'),
                    'edited_at' => $comment->edited_at?->toIso8601String(),
                    'can_edit' => $comment->user_id === request()->user()->id,
                ]),
            'events' => $issue->events()
                ->with('actor:id,name')
                ->unless($staff, fn ($q) => $q->public())
                ->get()
                // Filtered here, before the payload, not in the component.
                ->filter(fn ($event) => $staff || $event->type->isClientSafe())
                ->values()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'data' => $event->data,
                    'actor' => $event->actor === null ? null : $author($event->actor),
                    'is_internal' => $event->is_internal,
                    'created_at' => $event->created_at->format('Y-m-d\TH:i:s.uP'),
                ]),
            'attachments' => $issue->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'url' => route('attachments.show', $attachment),
                'is_image' => $attachment->isImage(),
                'uploaded_by' => $attachment->uploadedBy === null ? null : $author($attachment->uploadedBy)['name'],
                'created_at' => $attachment->created_at->toIso8601String(),
            ]),
            'statuses' => $this->statusesFor($issue->project),
            'facets' => $this->facets(),
            // Who a message from the composer reaches, in words, for the line under
            // it. Staff only; a client's message is always public and always theirs.
            'composer' => $staff ? [
                'internal' => 'Internal — staff only',
                'public' => $audienceLabel,
                'can_await' => app(\App\Support\Issues\ClientConversation::class)->canAwait($issue),
                'awaiting_status' => $issue->project->awaitingClientStatus()?->name,
            ] : null,
            'can' => [
                'update' => request()->user()->can('update', $issue),
                'comment_internally' => request()->user()->can('commentInternally', $issue),
                'attach' => request()->user()->can('comment', $issue),
                'delete' => request()->user()->can('delete', $issue),
            ],
        ]);
    }

    public function update(UpdateIssueRequest $request, Issue $issue, UpdateIssue $action): RedirectResponse
    {
        $this->authorize('update', $issue);

        $updated = $action->handle($issue, $request->validated(), $request->user());

        // Who can see an issue is the one sidebar change worth confirming out loud:
        // getting it wrong shows a client something, and nothing else on the page
        // would say so.
        if ($request->hasAny(['visibility', 'client_audience', 'client_share_ids'])) {
            return back()->with('success', app(\App\Support\Issues\ClientAudienceSummary::class)->label($updated).'.');
        }

        // Inertia turns this into a partial reload of the page the edit came from,
        // so inline edits in the list do not navigate anywhere.
        return back();
    }

    /** Apply one change to many issues, as one authorised, audited batch. */
    public function bulk(Request $request, UpdateIssue $action): RedirectResponse
    {
        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:200'],
            'keys.*' => ['string'],
            'changes' => ['required', 'array', 'min:1'],
        ]);

        // Scoped, so keys from another workspace resolve to nothing.
        $issues = Issue::whereIn('key', $validated['keys'])->get();

        $changes = collect($validated['changes'])
            ->only(['status_id', 'assignee_id', 'priority', 'type', 'visibility'])
            ->all();

        abort_if($changes === [], 422, 'No supported changes given.');

        DB::transaction(function () use ($issues, $changes, $action, $request) {
            foreach ($issues as $issue) {
                // Authorised per issue: a bulk action is not a way around a policy.
                $this->authorize('update', $issue);

                $action->handle($issue, $changes, $request->user());
            }
        });

        return back()->with('success', $issues->count().' issues updated.');
    }

    public function destroy(Issue $issue): RedirectResponse
    {
        $this->authorize('delete', $issue);

        $key = $issue->key;
        $issue->delete();

        return redirect()
            ->route('issues.index')
            ->with('success', "{$key} deleted.");
    }

    /** @return array<string, mixed> */
    private function facets(): array
    {
        $user = request()->user();
        $staff = $this->isStaff($user);

        return [
            'projects' => Project::active()->visibleTo($user)->orderBy('name')
                ->get(['id', 'name', 'key', 'slug'])
                ->map->only(['id', 'name', 'key', 'slug']),

            // Clients get only the labels actually on work they can see. The full
            // list is the team's own vocabulary and says plenty about other clients.
            'labels' => Label::query()
                ->unless($staff, fn ($q) => $q->whereHas(
                    'issues',
                    fn ($issues) => $issues->visibleToClient($user),
                ))
                ->orderBy('name')->get(['id', 'name', 'color'])
                ->map->only(['id', 'name', 'color']),

            // Clients cannot assign anything, so the staff list is of no use to them
            // and is simply a list of names they have not been introduced to.
            'members' => $staff
                ? $this->tenancy->currentOrFail()->members()
                    ->orderBy('name')->get(['users.id', 'users.name'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                : collect(),
            // Who an issue can be given to: staff only, the same rule the server
            // enforces. Every assignee picker reads this, never `members`, which also
            // holds clients so that filter chips can name a reporter.
            'assignees' => $staff
                ? $this->tenancy->currentOrFail()->members()
                    ->wherePivotIn('role', \App\Support\Issues\Assignable::roles())
                    ->orderBy('name')->get(['users.id', 'users.name'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                : collect(),
            'priorities' => IssuePriority::options(),
            'types' => IssueType::options(),
            // Keyed by project: the list spans projects and each has its own workflow.
            'statuses_by_project' => Status::query()
                ->whereIn('project_id', Project::visibleTo($user)->select('projects.id'))
                ->orderBy('position')
                ->get()
                ->groupBy('project_id')
                ->map(fn ($statuses) => $statuses->map(fn (Status $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'category' => $s->category->value,
                    'color' => $s->color,
                    'position' => $s->position,
                    'open' => $s->category->isOpen(),
                    // The board draws the count against this; null means no limit.
                    'wip_limit' => $s->wip_limit,
                ])->values()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function statusesFor(Project $project): array
    {
        return $project->statuses()->get()->map(fn (Status $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'category' => $s->category->value,
            'color' => $s->color,
            'open' => $s->category->isOpen(),
        ])->all();
    }

    /** @return array<string, mixed> */
    /**
     * What the reporter's browser saw, and how often this has happened.
     *
     * Taken from the most recent report rather than the first: a bug that is still
     * happening is best described by the last person it happened to, on whatever
     * they are running now.
     *
     * @return array<string, mixed>|null
     */
    private function diagnostics(Issue $issue): ?array
    {
        $report = $issue->reports()->first();

        if ($report === null && $issue->occurrence_count <= 1) {
            return null;
        }

        return [
            'occurrence_count' => $issue->occurrence_count,
            'first_seen_at' => $issue->first_seen_at?->toIso8601String(),
            'last_seen_at' => $issue->last_seen_at?->toIso8601String(),
            'environment' => $report?->environment,
            'console' => $report?->console,
            'network' => $report?->network,
            'error' => $report?->error,
        ];
    }

    private function summary(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'key' => $issue->key,
            'title' => $issue->title,
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'priority_label' => $issue->priority->label(),
            'priority_color' => $issue->priority->color(),
            'status' => [
                'id' => $issue->status->id,
                'name' => $issue->status->name,
                'category' => $issue->status->category->value,
                'color' => $issue->status->color,
                'position' => $issue->status->position,
                'open' => $issue->status->category->isOpen(),
            ],
            // A client reads the team as the workspace unless it shows its staff.
            'assignee' => $issue->assignee === null ? null : [
                'id' => $issue->assignee->id,
                'name' => $this->viewerIsStaff()
                    ? $issue->assignee->name
                    : AuthorLabel::for($issue->assignee, $this->tenancy->currentOrFail(), readerIsStaff: false),
            ],
            'labels' => $issue->labels->map->only(['id', 'name', 'color']),
            // The team's cue that a client has answered. Staff only.
            'client_replied' => $this->viewerIsStaff() && $issue->client_replied_at !== null,
            // Null-safe as well as scoped. The scope should mean this never sees a
            // deleted project, and a crash in a list is a bad way to find out it did.
            'project' => $issue->project?->only(['id', 'key', 'name', 'slug']),
            'updated_at' => $issue->updated_at->toIso8601String(),
        ];
    }

    private ?bool $viewerIsStaff = null;

    /** Whether whoever is reading is on the team. Asked once per request. */
    private function viewerIsStaff(): bool
    {
        return $this->viewerIsStaff ??= request()->user() !== null && $this->isStaff(request()->user());
    }

    private function isStaff(User $user): bool
    {
        return $user->membershipIn($this->tenancy->currentOrFail())?->isStaff() ?? false;
    }
}
