<?php

namespace App\Support\Workload;

use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\TimeOff;
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
 * **Days off.** Public holidays and each person's leave (TimeOff) are days with no
 * hours in them: work is spread only over the days its assignee is in, and a week's
 * capacity is their weekly hours times the share of its five weekdays they are
 * working. Three days' leave leaves two-fifths of a week.
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

    /** @var array{holidays: array<string, float>, leave: array<int, array<string, float>>, seen: array<int, true>}|null */
    private ?array $calendar = null;

    private bool $gridCalendar = false;

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
                foreach ($this->spread($issue, $person->id) as $week => $minutes) {
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

            $hours = $person->pivot->weekly_hours;

            foreach ($cells as $week => &$cell) {
                $cell['minutes'] = (int) round($cell['minutes']);
                usort($cell['issues'], fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

                // Weekdays off this week, half days as halves, and what is left of
                // their hours for it.
                $monday = CarbonImmutable::parse($week);
                $off = 5 - array_sum(array_map(fn (int $i) => $this->available($person->id, $monday->addDays($i)), range(0, 4)));
                // Whole numbers stay whole: "3d off", not "3.0d off".
                $cell['off'] = floor($off) === $off ? (int) $off : $off;
                $cell['capacity'] = $hours === null ? null : (int) round((float) $hours * 60 * (5 - $off) / 5);
            }
            unset($cell);

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

        // In the workspace's own order of disciplines; anybody without one last, under
        // a heading that says so.
        $order = array_flip($this->workspace->disciplines());

        return $rows
            ->groupBy(fn (array $row) => $row['discipline'] ?? '')
            ->sortBy(fn ($people, string $discipline) => $discipline === '' ? PHP_INT_MAX : ($order[$discipline] ?? PHP_INT_MAX - 1))
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
                foreach ($this->spread($issue, null) as $week => $minutes) {
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
        $this->loadCalendar($since, $until);

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

        return $people->map(function (User $person) use ($closed, $logged, $since, $until) {
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
                // Their weekly hours over the weekdays they were in: leave and holidays
                // are not hours anybody had.
                'available' => $hours === null ? null : (int) round((float) $hours * 60 / 5 * $this->workingDays($person->id, $since, $until)),
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------------ internals

    /**
     * One issue's remaining minutes, by the Monday of each week they fall in.
     *
     * @return array<string, float>
     */
    public function spread(Issue $issue, ?int $personId = null): array
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
            fn (CarbonImmutable $day) => $this->available($personId, $day),
        );
    }

    /**
     * Spread minutes over the working days from $start to $end, moved up to today
     * when they have gone by, and add them up by week.
     *
     * $available says how much of a day somebody is in: 1 for a normal weekday, 0
     * for a weekend, a holiday or leave, and a half for a half day. Each day takes a
     * share of the work in proportion, so a morning off takes half a day's share. A
     * span with no working time in it at all goes on the next day that has some,
     * which is when anybody will actually pick it up.
     *
     * @param  (callable(CarbonImmutable): (float|bool))|null  $available
     * @return array<string, float>
     */
    public static function distribute(int $minutes, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today, ?callable $available = null): array
    {
        $available ??= fn (CarbonImmutable $day) => ! $day->isWeekend();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $start = $start->max($today);
        $end = $end->max($today);

        /** @var array<string, float> $days ISO date => share of a day */
        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            if (($share = (float) $available($day)) > 0) {
                $days[$day->toDateString()] = $share;
            }
        }

        // Nothing in the span: the next day they are in, within a quarter. Beyond
        // that the answer is somebody on very long leave, and the day after the span
        // is as good as any.
        for ($day = $end->addDay(), $tries = 0; $days === [] && $tries < 90; $day = $day->addDay(), $tries++) {
            if (($share = (float) $available($day)) > 0) {
                $days[$day->toDateString()] = $share;
            }
        }

        $days = $days ?: [$end->addDay()->toDateString() => 1.0];
        $total = array_sum($days);
        $weeks = [];

        foreach ($days as $date => $share) {
            $week = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
            $weeks[$week] = ($weeks[$week] ?? 0) + $minutes * $share / $total;
        }

        return $weeks;
    }

    /**
     * How much of a day somebody is working: 1, 0, or a half for a half day off.
     * Null is nobody in particular, for whom only holidays count.
     */
    private function available(?int $personId, CarbonImmutable $day): float
    {
        // The grid's own window, once, whatever else has been loaded already.
        if (! $this->gridCalendar) {
            $this->gridCalendar = true;
            $this->loadCalendar($this->today->min($this->from), $this->from->addWeeks($this->weeks));
        }

        if ($day->isWeekend()) {
            return 0.0;
        }

        $date = $day->toDateString();
        $off = ($this->calendar['holidays'][$date] ?? 0)
            + ($personId === null ? 0 : ($this->calendar['leave'][$personId][$date] ?? 0));

        return max(0.0, 1 - $off);
    }

    /** Working days between two dates, counting a half day as half. */
    private function workingDays(int $personId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $days = 0.0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days += $this->available($personId, $day);
        }

        return $days;
    }

    /**
     * Every day off between two dates, as how much of each date is off. Widened
     * rather than replaced when asked again, so the grid and the actuals share one
     * calendar — and each entry is added once, or a half day loaded by both would be
     * counted as a whole one. A morning and an afternoon on the same day make a day;
     * nothing makes more than one.
     *
     * @return array{holidays: array<string, float>, leave: array<int, array<string, float>>, seen: array<int, true>}
     */
    private function loadCalendar(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendar = $this->calendar ?? ['holidays' => [], 'leave' => [], 'seen' => []];
        // Everything a spread might reach: a span moved to today can run past the
        // grid, and one with no working day looks up to a quarter beyond its end.
        $to = $to->addDays(120);

        foreach (TimeOff::overlapping($from->toDateString(), $to->toDateString())->get() as $off) {
            if (isset($calendar['seen'][$off->id])) {
                continue;
            }

            $calendar['seen'][$off->id] = true;

            for ($day = CarbonImmutable::parse($off->starts_on->toDateString()); $day->lessThanOrEqualTo($off->ends_on); $day = $day->addDay()) {
                $date = $day->toDateString();

                if ($off->user_id === null) {
                    $calendar['holidays'][$date] = min(1.0, ($calendar['holidays'][$date] ?? 0) + $off->share());
                } else {
                    $calendar['leave'][$off->user_id][$date] = min(1.0, ($calendar['leave'][$off->user_id][$date] ?? 0) + $off->share());
                }
            }
        }

        return $this->calendar = $calendar;
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
