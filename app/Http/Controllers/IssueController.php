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
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Issue::class);

        $filters = $this->filters($request);

        return Inertia::render('issues/index', [
            'issues' => $this->query($request, $filters)
                ->with(['status:id,name,category,color', 'assignee:id,name', 'labels:id,name,color', 'project:id,key,slug,name'])
                ->orderByRaw('priority DESC, updated_at DESC')
                // A hard ceiling until M3 adds virtualised paging; the list is grouped
                // client-side and 500 rows is already past what anyone reads.
                ->limit(500)
                ->get()
                ->map($this->summary(...))
                ->values(),
            'filters' => $filters,
            'facets' => $this->facets(),
        ]);
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
        $data = $request->validated();

        // A client filing an issue is filing it about their own project, so it must
        // stay visible to them. Otherwise they lose sight of it the moment it is created.
        if (! $this->isStaff($request->user())) {
            $data['visibility'] = IssueVisibility::Client->value;
            $data['assignee_id'] = null;
        }

        $issue = $action->handle($project, $data, $request->user());

        return redirect()
            ->route('issues.show', $issue)
            ->with('success', "{$issue->key} created.");
    }

    public function show(Issue $issue): Response
    {
        $this->authorize('view', $issue);

        $issue->load([
            'status', 'project', 'assignee', 'reporter', 'labels',
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
            'statuses' => $this->statusesFor($issue->project),
            'facets' => $this->facets(),
            'can' => [
                'update' => request()->user()->can('update', $issue),
                'comment_internally' => request()->user()->can('commentInternally', $issue),
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

    public function destroy(Issue $issue): RedirectResponse
    {
        $this->authorize('delete', $issue);

        $key = $issue->key;
        $issue->delete();

        return redirect()
            ->route('issues.index')
            ->with('success', "{$key} deleted.");
    }

    /** @return Builder<Issue> */
    private function query(Request $request, array $filters): Builder
    {
        $user = $request->user();

        $query = Issue::query()
            ->unless($this->isStaff($user), fn (Builder $q) => $q->visibleToClient($user));

        if ($filters['q']) {
            $query->search($filters['q']);
        }

        if ($filters['project']) {
            $query->whereHas('project', fn (Builder $q) => $q->where('slug', $filters['project']));
        }

        match ($filters['state']) {
            'open' => $query->open(),
            'closed' => $query->closed(),
            default => null,
        };

        if ($filters['assignee'] === 'none') {
            $query->whereNull('assignee_id');
        } elseif ($filters['assignee'] === 'me') {
            $query->where('assignee_id', $user->id);
        } elseif ($filters['assignee']) {
            $query->where('assignee_id', $filters['assignee']);
        }

        if ($filters['label']) {
            $query->whereHas('labels', fn (Builder $q) => $q->whereKey($filters['label']));
        }

        if ($filters['type']) {
            $query->where('type', $filters['type']);
        }

        if ($filters['priority'] !== null) {
            $query->where('priority', $filters['priority']);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'q' => $request->string('q')->trim()->toString() ?: null,
            'project' => $request->string('project')->toString() ?: null,
            'state' => in_array($request->string('state')->toString(), ['open', 'closed', 'all'], true)
                ? $request->string('state')->toString()
                : 'open',
            'assignee' => $request->string('assignee')->toString() ?: null,
            'label' => $request->integer('label') ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'priority' => $request->has('priority') && $request->input('priority') !== ''
                ? $request->integer('priority')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function facets(): array
    {
        return [
            'projects' => Project::active()->orderBy('name')
                ->get(['id', 'name', 'key', 'slug'])
                ->map->only(['id', 'name', 'key', 'slug']),
            'labels' => Label::orderBy('name')->get(['id', 'name', 'color'])
                ->map->only(['id', 'name', 'color']),
            'members' => $this->tenancy->currentOrFail()->members()
                ->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'priorities' => IssuePriority::options(),
            'types' => IssueType::options(),
            // Keyed by project: the list spans projects and each has its own workflow.
            'statuses_by_project' => Status::query()
                ->orderBy('position')
                ->get()
                ->groupBy('project_id')
                ->map(fn ($statuses) => $statuses->map(fn (Status $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'category' => $s->category->value,
                    'color' => $s->color,
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
