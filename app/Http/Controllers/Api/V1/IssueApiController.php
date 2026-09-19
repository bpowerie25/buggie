<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateIssue;
use App\Actions\UpdateIssue;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Models\Issue;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues over HTTP.
 *
 * Deliberately thin: it reuses the same policies, the same form requests and the
 * same actions as the screens do. An API with its own idea of what is allowed is an
 * API that eventually disagrees with the UI, and the disagreement is a security bug
 * rather than an inconsistency.
 */
class IssueApiController extends Controller
{
    public function __construct(
        private IssueQueryFilter $filter,
        private Tenancy $tenancy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Issue::class);

        $query = IssueQuery::parse($request->string('q')->toString());

        $issues = $this->filter->apply(
            Issue::query()->unless(
                $this->isStaff($request),
                fn (Builder $q) => $q->visibleToClient($request->user()),
            ),
            $query,
            $request->user(),
        )
            ->with(['status:id,name,category', 'assignee:id,name', 'labels:id,name', 'project:id,key,name,slug'])
            ->orderByRaw('priority DESC, updated_at DESC')
            ->paginate(min((int) $request->integer('per_page', 50), 100))
            ->withQueryString();

        return response()->json([
            'data' => collect($issues->items())->map($this->summary(...))->values(),
            'meta' => [
                'page' => $issues->currentPage(),
                'per_page' => $issues->perPage(),
                'total' => $issues->total(),
                'last_page' => $issues->lastPage(),
                // Echoed back canonicalised, so a caller can see how their query was
                // understood rather than guessing.
                'query' => (string) $query,
            ],
        ]);
    }

    public function show(Issue $issue): JsonResponse
    {
        $this->authorize('view', $issue);

        $issue->load(['status:id,name,category', 'assignee:id,name', 'reporter:id,name', 'labels:id,name', 'project:id,key,name,slug']);

        return response()->json(['data' => $this->detail($issue)]);
    }

    public function store(StoreIssueRequest $request, CreateIssue $action): JsonResponse
    {
        $this->authorize('create', Issue::class);

        $project = \App\Models\Project::findOrFail($request->integer('project_id'));

        $issue = $action->handle($project, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($issue->fresh()->load([
            'status:id,name,category', 'assignee:id,name', 'reporter:id,name',
            'labels:id,name', 'project:id,key,name,slug',
        ]))], 201);
    }

    public function update(UpdateIssueRequest $request, Issue $issue, UpdateIssue $action): JsonResponse
    {
        $this->authorize('update', $issue);

        $action->handle($issue, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($issue->fresh()->load([
            'status:id,name,category', 'assignee:id,name', 'reporter:id,name',
            'labels:id,name', 'project:id,key,name,slug',
        ]))]);
    }

    /** @return array<string, mixed> */
    private function summary(Issue $issue): array
    {
        return [
            'key' => $issue->key,
            'title' => $issue->title,
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'status' => [
                'name' => $issue->status->name,
                'category' => $issue->status->category->value,
                'open' => $issue->status->category->isOpen(),
            ],
            'project' => [
                'key' => $issue->project->key,
                'slug' => $issue->project->slug,
                'name' => $issue->project->name,
            ],
            'assignee' => $issue->assignee?->only(['id', 'name']),
            'labels' => $issue->labels->pluck('name'),
            'occurrences' => $issue->occurrence_count,
            'created_at' => $issue->created_at?->toIso8601String(),
            'updated_at' => $issue->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Issue $issue): array
    {
        return [
            ...$this->summary($issue),
            'description' => $issue->description,
            'reporter' => $issue->reporter?->only(['id', 'name']),
            'visibility' => $issue->visibility->value,
            'due_on' => $issue->due_on?->toDateString(),
            'closed_at' => $issue->closed_at?->toIso8601String(),

            /*
             * Keyed by the field's key, so a client can read and write the same
             * shape: {"custom_fields": {"environment": "Production"}}.
             *
             * A token belonging to a client is subject to the same visibility rule as
             * the screens — the API disagreeing with the UI about who may see what is
             * a mistake this codebase has already made once.
             */
            'custom_fields' => collect(
                app(\App\Support\CustomFields\FieldValues::class)
                    ->forIssue($issue, clientOnly: ! $this->isStaff(request()))
            )->mapWithKeys(fn (array $field) => [$field['key'] => $field['value']]),
        ];
    }

    private function isStaff(Request $request): bool
    {
        return $request->user()->membershipIn($this->tenancy->currentOrFail())?->isStaff() ?? false;
    }
}
