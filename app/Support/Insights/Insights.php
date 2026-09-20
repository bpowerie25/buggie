<?php

namespace App\Support\Insights;

use App\Models\Issue;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the Insights screen.
 *
 * Every query here is an aggregate run by the database. The alternative — loading
 * issues and counting them in PHP — is fine on a seeded database and falls over on a
 * workspace with four years of history, which is exactly the workspace whose owner
 * cares about trends.
 */
class Insights
{
    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        private ?int $projectId = null,
    ) {}

    /**
     * How wide each bucket is.
     *
     * A year plotted by day is 365 unreadable columns; a fortnight plotted by month
     * is one. The boundaries are picked so a chart is always somewhere between about
     * a dozen and sixty points.
     */
    public function interval(): string
    {
        $days = $this->from->diffInDays($this->to);

        return match (true) {
            $days <= 62 => 'day',
            $days <= 400 => 'week',
            default => 'month',
        };
    }

    /** @return array<string, int> */
    public function headline(): array
    {
        $opened = $this->scoped()->whereBetween('issues.created_at', $this->window())->count();
        $closed = $this->scoped()->whereBetween('issues.closed_at', $this->window())->count();

        return [
            'opened' => $opened,
            'closed' => $closed,
            // Positive means the backlog grew. Stated as a change rather than as a
            // total, because the total is on the next card and the change is the
            // thing somebody is actually asking about.
            'net' => $opened - $closed,
            'open_now' => $this->scoped()->open()->count(),
            'reports' => Report::query()
                ->when($this->projectId, fn (Builder $q, $id) => $q->whereHas(
                    'widgetKey',
                    fn (Builder $k) => $k->where('project_id', $id),
                ))
                // reports, not issues: this one is not qualified because it never
                // joins anything.
                ->whereBetween('reports.created_at', $this->window())
                ->count(),
            /*
             * Occurrences past the first, across issues seen in the window.
             *
             * This is the number the product is sold on: forty people hitting one
             * broken checkout is one issue, and this says how many reports that
             * collapsing actually absorbed.
             */
            'collapsed' => max(0, (int) $this->scoped()
                ->whereBetween('issues.last_seen_at', $this->window())
                ->sum(DB::raw('issues.occurrence_count - 1'))),
        ];
    }

    /**
     * Median time from opening to closing, in minutes, for issues closed in the
     * window. Null when nothing closed.
     *
     * Median rather than mean: one bug that sat open for eight months drags an
     * average somewhere nobody recognises, and the question being asked is "how long
     * does a typical thing take".
     */
    public function medianTimeToCloseMinutes(): ?int
    {
        // toBase(), because an Eloquent value(DB::raw(...)) is read as an attribute
        // name and split on the comma inside percentile_cont(0.5). Scopes are still
        // applied — toBase() runs them before handing back the query builder — so
        // this remains workspace-scoped.
        $value = $this->scoped()
            ->whereBetween('issues.closed_at', $this->window())
            ->whereNotNull('issues.closed_at')
            ->toBase()
            ->selectRaw(
                'percentile_cont(0.5) within group '
                .'(order by extract(epoch from (issues.closed_at - issues.created_at))) as median'
            )
            ->value('median');

        return $value === null ? null : (int) round(((float) $value) / 60);
    }

    /**
     * Opened and closed per bucket, plus the running open backlog.
     *
     * @return array<int, array{bucket: string, opened: int, closed: int, open: int}>
     */
    public function throughput(): array
    {
        $opened = $this->countBy('issues.created_at');
        $closed = $this->countBy('issues.closed_at');

        // Where the backlog stood the instant before the window opened, so the line
        // starts from the truth rather than from zero.
        $running = $this->openAt($this->from);

        $rows = [];

        foreach ($this->buckets() as $bucket) {
            $in = $opened[$bucket] ?? 0;
            $out = $closed[$bucket] ?? 0;

            // Walked forward from a known starting point rather than counted per
            // bucket: one "how many were open on this day" query per point is sixty
            // queries for a two-month chart.
            $running += $in - $out;

            $rows[] = [
                'bucket' => $bucket,
                'opened' => $in,
                'closed' => $out,
                'open' => max(0, $running),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{name: string, count: int}> */
    public function byProject(): array
    {
        return $this->breakdown(
            $this->scoped()->whereBetween('issues.created_at', $this->window())
                ->join('projects', 'projects.id', '=', 'issues.project_id')
                ->groupBy('projects.name')
                ->selectRaw('projects.name as name, count(*) as total'),
        );
    }

    /** @return array<int, array{name: string, count: int}> */
    public function byAssignee(): array
    {
        return $this->breakdown(
            $this->scoped()->open()
                ->leftJoin('users', 'users.id', '=', 'issues.assignee_id')
                ->groupBy('users.name')
                ->selectRaw("coalesce(users.name, 'Unassigned') as name, count(*) as total"),
        );
    }

    /** @return array<int, array{name: string, count: int}> */
    public function byStatus(): array
    {
        return $this->breakdown(
            $this->scoped()->open()
                ->join('statuses', 'statuses.id', '=', 'issues.status_id')
                ->groupBy('statuses.name')
                ->selectRaw('statuses.name as name, count(*) as total'),
        );
    }

    /**
     * The open issues nobody has touched for longest.
     *
     * Every tracker accumulates these and no list view surfaces them, because they
     * are never near the top of anything sorted by recency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ageing(int $limit = 8): array
    {
        return $this->scoped()->open()
            ->with(['status:id,name', 'project:id,name'])
            ->orderBy('issues.created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Issue $issue) => [
                'key' => $issue->key,
                'title' => $issue->title,
                'project' => $issue->project?->name,
                'status' => $issue->status?->name,
                'days' => (int) $issue->created_at->diffInDays(now()),
            ])
            ->all();
    }

    // ------------------------------------------------------------------ internals

    /**
     * Columns are qualified as issues.* throughout.
     *
     * Several of these queries join projects, users or statuses, and every one of
     * those tables has a created_at. Postgres rejects the ambiguity rather than
     * guessing, which is the good outcome — but only the breakdowns join anything,
     * so an unqualified column is a query that works until somebody opens the tab
     * with the chart on it.
     *
     * @return Builder<Issue>
     */
    private function scoped(): Builder
    {
        return Issue::query()
            ->when($this->projectId, fn (Builder $q, $id) => $q->where('issues.project_id', $id));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    /** How many issues were open immediately before a moment. */
    private function openAt(CarbonImmutable $moment): int
    {
        return $this->scoped()
            ->where('issues.created_at', '<', $moment->startOfDay())
            ->where(fn (Builder $q) => $q
                ->whereNull('issues.closed_at')
                ->orWhere('issues.closed_at', '>=', $moment->startOfDay()))
            ->count();
    }

    /**
     * @return array<string, int> bucket => count
     */
    private function countBy(string $column): array
    {
        return $this->scoped()
            ->whereBetween($column, $this->window())
            ->groupBy('bucket')
            ->selectRaw($this->truncate($column).' as bucket, count(*) as total')
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn ($total, $bucket) => [
                CarbonImmutable::parse($bucket)->toDateString() => (int) $total,
            ])
            ->all();
    }

    /**
     * Bucket a timestamp column.
     *
     * Converted into the application's timezone before truncating. Stored timestamps
     * are UTC, and for anywhere east or west of it a bug closed just before midnight
     * local time otherwise lands in the wrong day — which is visible on a daily chart
     * and invisible in a test written in UTC.
     */
    private function truncate(string $column): string
    {
        $tz = str_replace("'", '', (string) config('app.timezone', 'UTC'));

        return "date_trunc('{$this->interval()}', {$column} at time zone 'UTC' at time zone '{$tz}')";
    }

    /** @return array<int, string> */
    private function buckets(): array
    {
        $step = match ($this->interval()) {
            'day' => '1 day',
            'week' => '1 week',
            default => '1 month',
        };

        $cursor = match ($this->interval()) {
            'day' => $this->from->startOfDay(),
            'week' => $this->from->startOfWeek(),
            default => $this->from->startOfMonth(),
        };

        $buckets = [];

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $buckets[] = $cursor->toDateString();
            $cursor = $cursor->add($step);
        }

        return $buckets;
    }

    /**
     * @param  Builder<Issue>  $query
     * @return array<int, array{name: string, count: int}>
     */
    private function breakdown(Builder $query): array
    {
        return $query->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'count' => (int) $row->total])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }
}
