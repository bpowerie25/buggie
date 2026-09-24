<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use App\Support\Tenancy\Tenancy;
use App\Support\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * When the work is meant to happen.
 *
 * Issues as bars on a date axis, grouped by parent. The issue list answers "what is
 * outstanding"; this answers "what is meant to land in October", which no list
 * sorted by priority has ever answered.
 *
 * Staff only, like Insights and Time. A timeline is a plan across the whole
 * workspace, and a plan drawn for a client scoped to two projects out of twenty
 * either tells them about the other eighteen or is not a plan.
 */
class TimelineController extends Controller
{
    public function __construct(
        private Tenancy $tenancy,
        private IssueQueryFilter $filter,
    ) {}

    public function __invoke(Request $request): Response
    {
        // The same permission the issue list needs; there is no separate one to
        // invent for drawing the same rows differently.
        $this->authorize('viewAny', Issue::class);

        $staff = $request->user()->membershipIn($this->tenancy->currentOrFail())?->isStaff() ?? false;

        [$from, $to] = $this->range($request);

        $query = IssueQuery::parse($request->string('q')->toString());

        /*
         * A client sees one project's timeline at a time, and only a project they hold
         * whose team has chosen to show it: a plan across the workspace is a plan
         * across every client in it, and a plan is not something to reveal by
         * surprise. With none to show, there is no timeline at all — a 404, like
         * anything else a client has no business knowing exists.
         */
        $projects = $staff
            ? Project::active()->orderBy('name')->get(['id', 'name', 'slug', 'settings'])
            : Project::active()->visibleTo($request->user())->orderBy('name')
                ->get(['id', 'name', 'slug', 'settings'])
                ->filter(fn (Project $project) => $project->showsTimelineToClients())
                ->values();

        if (! $staff) {
            abort_if($projects->isEmpty(), 404);

            $asked = $query->first('project');
            $query = $query->without('project')->with(
                'project',
                $projects->contains('slug', $asked) ? $asked : $projects->first()->slug,
            );
        }

        $timeline = new Timeline($from, $to, $query, $request->user(), $this->filter, forClient: ! $staff);

        return Inertia::render('timeline/index', [
            'rows' => $timeline->rows(),
            'undated' => $timeline->undated(),
            'truncated' => $timeline->truncated(),
            'axis' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'interval' => $timeline->interval(),
                'ticks' => $timeline->ticks(),
                // Sent rather than read off the browser's clock: overdue is decided
                // here, in the application's timezone, and a "today" line drawn a
                // day away from the rows it explains is worse than no line.
                'today' => CarbonImmutable::now()->toDateString(),
            ],
            /*
             * The filter state is the query string and nothing else.
             *
             * Narrowing to a project is `project:web` in the query, not a project_id
             * parameter beside it — the dropdown edits the string the way the issue
             * list's chips do. A second representation of the same filter is how the
             * URL, the chips and a saved view drift apart.
             *
             * The dates are not part of it, because they are the axis rather than a
             * statement about an issue: they decide what is drawn, not what matches.
             */
            'query' => $query->toArray(),
            'projects' => $projects->map->only(['id', 'name', 'slug'])->values(),
            // Staff drag; a client reads.
            'editable' => $staff,
        ]);
    }

    /**
     * The window, which is mostly ahead of today.
     *
     * Insights defaults to the last ninety days because it reports what happened. A
     * plan is the other way round: two weeks back so a slip is visible next to what
     * it has pushed, and the rest forward.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))
            : CarbonImmutable::now()->subDays(14);

        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))
            : CarbonImmutable::now()->addDays(75);

        // Swapped rather than rejected, as on Insights: two date pickers make this
        // easy to do by accident and the intent is never in doubt.
        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
