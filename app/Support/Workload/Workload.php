<?php

namespace App\Support\Workload;

use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who has how much on, week by week, across every project.
 *
 * Workspace-wide by default, because a person's week is: 60% on one client and 70%
 * on another is somebody overbooked, and neither project's own view would say so. A
 * project filter narrows it to "who is on this".
 *
 * **What a week's number is.** Each open issue assigned to somebody, with an estimate
 * and at least one date, contributes what is *left* of its estimate — the estimate
 * less the time already logged against it, never below zero — spread evenly over the
 * working days from its start to its due date. Weekends take none. Work already late
 * is not left in the past, where nobody would see it: an open issue whose dates have
 * gone by lands on today, because that is when it is actually being done.
 *
 * **What it deliberately does not guess.** Work with no estimate, or no dates, has no
 * honest place in a week. It is counted per person instead, beside the grid, so a
 * plan full of holes looks like one rather than looking light.
 */
class Workload
{
    public function __construct(
        private Workspace $workspace,
        /** The Monday the grid starts on. */
        private CarbonImmutable $from,
        private int $weeks,
        private ?int $projectId = null,
        private ?CarbonImmutable $today = null,
    ) {
        $this->from = $from->startOfWeek();
        $this->today = ($today ?? CarbonImmutable::today())->startOfDay();
    }

    /** @return array<int, string> the Monday of each week shown */
    public function weeks(): array
    {
        return array_map(fn (int $i) => $this->from->addWeeks($i)->toDateString(), range(0, $this->weeks - 1));
    }

    /**
     * People grouped by discipline, each with a cell per week.
     *
     * @return array<int, array{discipline: string, people: array<int, array<string, mixed>>}>
     */
    public function groups(): array
    {
        $people = $this->staff();
        $issues = $this->openAssigned($people->pluck('id'));
        $weeks = $this->weeks();

        $rows = $people->map(function (User $person) use ($issues, $weeks) {
            $theirs = $issues->where('assignee_id', $person->id);
            $cells = array_fill_keys($weeks, ['minutes' => 0, 'issues' => []]);

            foreach ($theirs as $issue) {
                foreach ($this->spread($issue) as $week => $minutes) {
                    if (! isset($cells[$week])) {
                        continue;
                    }

                    $cells[$week]['minutes'] += $minutes;
                    $cells[$week]['issues'][] = [
                        'key' => $issue->key,
                        'title' => $issue->title,
                        'project' => $issue->project->name,
                        'minutes' => (int) round($minutes),
                    ];
                }
            }

            foreach ($cells as &$cell) {
                $cell['minutes'] = (int) round($cell['minutes']);
                usort($cell['issues'], fn ($a, $b) => $b['minutes'] <=> $a['minutes']);
            }

            $hours = $person->pivot->weekly_hours;

            return [
                'id' => $person->id,
                'name' => $person->name,
                'discipline' => $person->pivot->discipline,
                'weekly_minutes' => $hours === null ? null : (int) round((float) $hours * 60),
                'cells' => $cells,
                // The holes in the plan, counted rather than guessed at.
                'unestimated' => $theirs->whereNull('estimate_minutes')->count(),
                'undated' => $theirs->filter(fn (Issue $i) => $i->start_on === null && $i->due_on === null)->count(),
            ];
        });

        return $rows
            ->groupBy(fn (array $row) => $row['discipline'] ?? '')
            ->sortKeys()
            // Nobody given a discipline yet: last, under a heading that says so.
            ->sortBy(fn ($people, string $discipline) => $discipline === '' ? 1 : 0)
            ->map(fn (Collection $people, string $discipline) => [
                'discipline' => $discipline === '' ? 'No discipline set' : $discipline,
                'people' => $people->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Estimated, dated open work with nobody on it, per week: hours the plan needs
     * that no row above will show.
     *
     * @return array<string, int>
     */
    public function unassigned(): array
    {
        $cells = array_fill_keys($this->weeks(), 0.0);

        $this->scoped()->open()->whereNull('assignee_id')
            ->whereNotNull('estimate_minutes')
            ->where(fn (Builder $q) => $q->whereNotNull('start_on')->orWhereNotNull('due_on'))
            ->withSum('timeEntries as logged_minutes', 'minutes')
            ->get()
            ->each(function (Issue $issue) use (&$cells) {
                foreach ($this->spread($issue) as $week => $minutes) {
                    if (isset($cells[$week])) {
                        $cells[$week] += $minutes;
                    }
                }
            });

        return array_map(fn (float $m) => (int) round($m), $cells);
    }

    /**
     * Estimates against what the work actually took, and hours logged against hours
     * available, for a past period.
     *
     * - estimated / actual: over the issues each person closed in the period that had
     *   an estimate, the estimates added up and every hour logged on those issues
     *   (by anybody — it is what the issue cost). A ratio over 1 is under-estimating.
     * - logged / available: the person's own time entries in the period, and their
     *   weekly hours times the number of weeks in it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function actuals(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $people = $this->staff();
        $weeks = max($since->diffInDays($until) + 1, 1) / 7;

        $closed = $this->scoped()
            ->whereIn('assignee_id', $people->pluck('id'))
            ->whereNotNull('estimate_minutes')
            ->whereBetween('closed_at', [$since->startOfDay(), $until->endOfDay()])
            ->whereHas('status', fn (Builder $q) => $q->where('category', StatusCategory::Done->value))
            ->withSum('timeEntries as logged_minutes', 'minutes')
            ->get(['id', 'assignee_id', 'estimate_minutes']);

        $logged = TimeEntry::query()
            ->whereIn('user_id', $people->pluck('id'))
            ->whereBetween('spent_on', [$since->toDateString(), $until->toDateString()])
            ->when($this->projectId, fn (Builder $q, $id) => $q->whereHas('issue', fn (Builder $i) => $i->where('project_id', $id)))
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(minutes) as minutes')
            ->pluck('minutes', 'user_id');

        return $people->map(function (User $person) use ($closed, $logged, $weeks) {
            $theirs = $closed->where('assignee_id', $person->id);
            $hours = $person->pivot->weekly_hours;

            return [
                'id' => $person->id,
                'name' => $person->name,
                'discipline' => $person->pivot->discipline,
                'closed' => $theirs->count(),
                'estimated' => (int) $theirs->sum('estimate_minutes'),
                'actual' => (int) $theirs->sum('logged_minutes'),
                'logged' => (int) ($logged[$person->id] ?? 0),
                'available' => $hours === null ? null : (int) round((float) $hours * 60 * $weeks),
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------------ internals

    /**
     * One issue's remaining minutes, by the Monday of each week they fall in.
     *
     * @return array<string, float>
     */
    public function spread(Issue $issue): array
    {
        $remaining = max((int) $issue->estimate_minutes - (int) ($issue->logged_minutes ?? 0), 0);
        $first = $issue->start_on ?? $issue->due_on;
        $last = $issue->due_on ?? $issue->start_on;

        if ($remaining === 0 || $first === null) {
            return [];
        }

        return self::distribute(
            $remaining,
            CarbonImmutable::parse($first->toDateString()),
            CarbonImmutable::parse($last->toDateString()),
            $this->today,
        );
    }

    /**
     * Spread minutes evenly over the working days from $start to $end, moved up to
     * today when they have gone by, and add them up by week.
     *
     * A span with no working day in it — a weekend — goes on the Monday after, which
     * is when anybody will actually pick it up.
     *
     * @return array<string, float>
     */
    public static function distribute(int $minutes, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today): array
    {
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $start = $start->max($today);
        $end = $end->max($today);

        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $days[] = $day;
            }
        }

        if ($days === []) {
            $days = [$start->next(CarbonImmutable::MONDAY)];
        }

        $weeks = [];

        foreach ($days as $day) {
            $week = $day->startOfWeek()->toDateString();
            $weeks[$week] = ($weeks[$week] ?? 0) + $minutes / count($days);
        }

        return $weeks;
    }

    /** @return Collection<int, User> staff members, with their hours and discipline */
    private function staff(): Collection
    {
        return $this->workspace->members()
            ->wherePivot('role', '!=', WorkspaceRole::Client->value)
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    /**
     * Every open issue assigned to one of these people: the dated and estimated ones
     * for the grid, the rest for the counts of what is missing.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Issue>
     */
    private function openAssigned(Collection $ids): Collection
    {
        return $this->scoped()->open()
            ->whereIn('assignee_id', $ids)
            ->with('project:id,name')
            ->withSum('timeEntries as logged_minutes', 'minutes')
            ->get();
    }

    /** @return Builder<Issue> */
    private function scoped(): Builder
    {
        return Issue::query()->when($this->projectId, fn (Builder $q, $id) => $q->where('issues.project_id', $id));
    }
}
