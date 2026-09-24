<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\TimeOff;
use App\Support\Tenancy\Tenancy;
use App\Support\Workload\Workload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has how much on, and how estimates have compared with what work really took.
 *
 * Staff only, and not only because clients have no hours here: how busy the team is
 * and how far out its estimates run are the agency's business, not its customers'.
 */
class WorkloadController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): Response
    {
        $workspace = $tenancy->currentOrFail();

        abort_unless($request->user()->membershipIn($workspace)?->isStaff() ?? false, 404);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'weeks' => ['nullable', 'integer', 'in:4,8,12,26'],
            'project_id' => ['nullable', 'integer'],
            'since' => ['nullable', 'integer', 'in:30,90,180,365'],
        ]);

        $from = CarbonImmutable::parse($validated['from'] ?? 'today')->startOfWeek();
        $weeks = (int) ($validated['weeks'] ?? 8);
        // Scoped, so another workspace's id is simply no project.
        $projectId = isset($validated['project_id']) ? Project::whereKey($validated['project_id'])->value('id') : null;
        $since = (int) ($validated['since'] ?? 90);

        $workload = new Workload($workspace, $from, $weeks, $projectId);

        return Inertia::render('workload/index', [
            'weeks' => $workload->weeks(),
            'groups' => $workload->groups(),
            'unassigned' => $workload->unassigned(),
            'actuals' => $workload->actuals(CarbonImmutable::today()->subDays($since - 1), CarbonImmutable::today()),
            'today' => CarbonImmutable::today()->toDateString(),
            'filters' => ['from' => $from->toDateString(), 'weeks' => $weeks, 'project_id' => $projectId, 'since' => $since],
            'projects' => Project::active()->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage = $request->user()->can('create', Invitation::class),

            // Time off from last week onwards, holidays first, then by date.
            'timeOff' => TimeOff::query()
                ->where('ends_on', '>=', CarbonImmutable::today()->subWeek()->toDateString())
                ->with('user:id,name')
                ->orderByRaw('user_id IS NOT NULL')
                ->orderBy('starts_on')
                ->limit(200)
                ->get()
                ->map(fn (TimeOff $off) => [
                    'id' => $off->id,
                    'who' => $off->user?->name,
                    'starts_on' => $off->starts_on->toDateString(),
                    'ends_on' => $off->ends_on->toDateString(),
                    'note' => $off->note,
                    'can_delete' => $canManage || $off->user_id === $request->user()->id,
                ]),
            // Whose leave can be booked from this screen: anybody's for an admin, your
            // own otherwise.
            'staff' => $workspace->members()->wherePivot('role', '!=', WorkspaceRole::Client->value)
                ->unless($canManage, fn ($q) => $q->whereKey($request->user()->id))
                ->orderBy('name')->get(['users.id', 'users.name'])
                ->map->only(['id', 'name']),
            'me' => $request->user()->id,
        ]);
    }
}
