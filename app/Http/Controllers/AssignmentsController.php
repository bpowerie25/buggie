<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Issues\Assignable;
use App\Support\Tenancy\ProjectScope;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has what: every member of the team against every project, in open issues.
 *
 * Every cell links to the issue list filtered to that person (and project), where the
 * existing bulk edit reassigns — which is the whole point when somebody is off sick.
 * No second way to reassign lives here.
 *
 * Staff only, like Insights and Workload: how the work is spread across the team is
 * the agency's business.
 */
class AssignmentsController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): Response
    {
        $workspace = $tenancy->currentOrFail();

        abort_unless($request->user()->membershipIn($workspace)?->isStaff() ?? false, 404);

        $this->authorize('viewAny', Issue::class);

        // The sidebar's project choice narrows the columns, like every other screen.
        $scoped = app(ProjectScope::class)->current();

        $projects = Project::active()
            ->when($scoped, fn ($q) => $q->whereKey($scoped->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'key', 'slug']);

        // One aggregate query for the whole grid.
        $counts = Issue::query()->open()
            ->whereIn('project_id', $projects->modelKeys())
            ->selectRaw('assignee_id, project_id, count(*) as total')
            ->selectRaw('sum(case when due_on is not null and due_on < ? then 1 else 0 end) as overdue', [now()->toDateString()])
            ->groupBy('assignee_id', 'project_id')
            ->get();

        $people = $workspace->members()
            ->wherePivotIn('role', Assignable::roles())
            ->orderBy('name')
            ->get(['users.id', 'users.name']);

        $row = function (?int $id, string $name) use ($counts, $projects) {
            $mine = $counts->where('assignee_id', $id);

            return [
                'id' => $id,
                'name' => $name,
                'cells' => $projects->mapWithKeys(fn (Project $p) => [
                    $p->id => (int) ($mine->firstWhere('project_id', $p->id)->total ?? 0),
                ]),
                'total' => (int) $mine->sum('total'),
                'overdue' => (int) $mine->sum('overdue'),
            ];
        };

        return Inertia::render('assignments/index', [
            'projects' => $projects,
            'people' => $people->map(fn ($u) => $row($u->id, $u->name))->values(),
            'unassigned' => $row(null, 'Unassigned'),
            'can_reassign' => $request->user()->can('update', new Issue),
        ]);
    }
}
