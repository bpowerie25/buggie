<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Insights\Insights;
use App\Support\Time\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the work went.
 *
 * Called Insights rather than Reports because a Report already means something here:
 * the thing the widget sends. Two meanings for one word in one product is how a
 * support conversation goes wrong.
 *
 * Staff only, like Time. Every figure is an aggregate across the whole workspace, and
 * a client scoped to two projects out of twenty cannot be shown a workspace total
 * without it telling them about the other eighteen.
 */
class InsightsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Seeing every issue in the workspace is the same permission this needs;
        // there is no separate one to invent.
        $this->authorize('viewAny', Issue::class);

        abort_unless(
            $request->user()->membershipIn(
                app(\App\Support\Tenancy\Tenancy::class)->currentOrFail()
            )?->isStaff() ?? false,
            403,
        );

        [$from, $to] = $this->range($request);

        $projectId = $request->integer('project_id') ?: null;

        $insights = new Insights($from, $to, $projectId);

        $median = $insights->medianTimeToCloseMinutes();

        return Inertia::render('insights/index', [
            'headline' => $insights->headline(),
            'median' => [
                'minutes' => $median,
                // Days once it is past a couple, because "4,320m" is not an answer
                // anybody can picture.
                'label' => $median === null
                    ? null
                    : ($median >= 2880
                        ? round($median / 1440, 1).' days'
                        : Duration::format($median)),
            ],
            'throughput' => $insights->throughput(),
            'interval' => $insights->interval(),
            'byProject' => $insights->byProject(),
            'byAssignee' => $insights->byAssignee(),
            'byStatus' => $insights->byStatus(),
            'ageing' => $insights->ageing(),
            'blockers' => $insights->blockers(),
            'delaysCaused' => $insights->delaysCaused(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'project_id' => $projectId,
            ],
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        // Ninety days by default rather than this calendar month: a trend needs
        // enough points to be a trend, and on the second of the month a
        // month-to-date chart has two.
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))
            : CarbonImmutable::now()->subDays(89);

        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))
            : CarbonImmutable::now();

        // Swapped rather than rejected: a date picker makes this easy to do by
        // accident and the intent is never in doubt.
        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
