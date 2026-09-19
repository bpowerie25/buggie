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
            'issues' => fn () => $this->issues($request, $query),
            'query' => $query->toArray(),
            'layout' => $request->string('layout')->toString() === 'board' ? 'board' : 'list',
            'groupBy' => $this->groupBy($request),
            'facets' => fn () => $this->facets(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function issues(Request $request, IssueQuery $query): \Illuminate\Support\Collection
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
            ->orderByRaw('priority DESC, updated_at DESC')
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
            'status', 'project', 'assignee', 'reporter', 'labels',
            'attachments.uploadedBy:id,name',
            'watchers:id,name',
            'relations.relatedIssue:id,key,title,status_id',
            'relations.relatedIssue.status:id,name,category,color',
        ]);

        $staff = $this->isStaff(request()->user());

        return Inertia::render('issues/show', [
            'issue' => [
                ...$this->summary($issue),
                'description' => $issue->description,
                'reporter' => $issue->reporter?->only(['id', 'name']),
                'visibility' => $issue->visibility->value,
                'due_on' => $issue->due_on?->toDateString(),
                'created_at' => $issue->created_at->toIso8601String(),
                'watchers' => $issue->watchers->map->only(['id', 'name']),
                'relations' => $issue->relations->map(fn ($relation) => [
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

            // The one place the client visibility plane is enforced for reading.
            'comments' => $issue->comments()
                ->with('author:id,name')
                ->unless($staff, fn ($q) => $q->public())
                ->get()
                ->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $comment->is_internal,
                    'author' => $comment->author?->only(['id', 'name']),
                    // Microseconds preserved so the merged feed sorts deterministically.
                    'created_at' => $comment->created_at->format('Y-m-d\TH:i:s.uP'),
                    'edited_at' => $comment->edited_at?->toIso8601String(),
                    'can_edit' => $comment->user_id === request()->user()->id,
                ]),
            'events' => $issue->events()
                ->with('actor:id,name')
                ->unless($staff, fn ($q) => $q->public())
                ->get()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'data' => $event->data,
                    'actor' => $event->actor?->only(['id', 'name']),
                    'created_at' => $event->created_at->format('Y-m-d\TH:i:s.uP'),
                ]),
            'attachments' => $issue->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'url' => route('attachments.show', $attachment),
                'is_image' => $attachment->isImage(),
                'uploaded_by' => $attachment->uploadedBy?->name,
                'created_at' => $attachment->created_at->toIso8601String(),
            ]),
            'statuses' => $this->statusesFor($issue->project),
            'facets' => $this->facets(),
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

        $action->handle($issue, $request->validated(), $request->user());

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
            'assignee' => $issue->assignee?->only(['id', 'name']),
            'labels' => $issue->labels->map->only(['id', 'name', 'color']),
            'project' => $issue->project->only(['id', 'key', 'name', 'slug']),
            'updated_at' => $issue->updated_at->toIso8601String(),
        ];
    }

    private function isStaff(User $user): bool
    {
        return $user->membershipIn($this->tenancy->currentOrFail())?->isStaff() ?? false;
    }
}
