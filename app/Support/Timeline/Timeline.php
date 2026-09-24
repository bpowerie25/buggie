<?php

namespace App\Support\Timeline;

use App\Enums\RelationType;
use App\Models\Issue;
use App\Models\User;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use App\Support\Time\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The rows behind the Timeline screen.
 *
 * Unlike Insights, which is aggregates and therefore SQL, this is one page of issues
 * turned into bars. The work is in deciding where each bar begins and ends, and in
 * refusing to draw one where the answer is not known.
 *
 * **What stands in for a missing start date: nothing.**
 *
 * An issue with a due date and no start date is drawn as a *milestone* — a marker on
 * the due date — rather than as a bar. The two obvious substitutes both lie:
 *
 * - `due_on` minus the estimate makes a two-hour issue due in three weeks into a
 *   two-hour sliver, which says nothing about when anybody means to start it.
 * - `created_at` is a fact about when somebody typed the issue in. An issue raised in
 *   January and due in September becomes an eight-month bar nobody committed to, and
 *   a reader has no way to tell it apart from one somebody actually planned.
 *
 * A milestone claims only what is known: this is due then. The same goes the other
 * way — a start date with no due date is a marker on the start, not a bar running off
 * the right-hand edge.
 *
 * Every relation this reads is eager-loaded in one place, because a timeline is a
 * loop over issues and strict mode turns the first forgotten one into a 500.
 */
class Timeline
{
    /**
     * A hard ceiling on rows.
     *
     * A Gantt with a thousand bars is a texture, not a chart. The count is reported
     * so the screen can say it is showing part of the answer rather than quietly
     * showing the wrong one.
     */
    private const LIMIT = 500;

    /** @var array{rows: array<int, array<string, mixed>>, undated: array<int, array<string, mixed>>, total: int}|null */
    private ?array $built = null;

    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        private IssueQuery $query,
        private User $viewer,
        private IssueQueryFilter $filter,
    ) {}

    /**
     * How wide each axis label is spaced.
     *
     * The same boundaries as Insights::interval(), and for the same reason: a year at
     * day resolution is 365 unreadable gridlines. A timeline additionally has to draw
     * bars inside those gridlines, so it matters more here, not less.
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

    /**
     * Where the axis labels sit, as ISO dates.
     *
     * Bucket starts rather than evenly spaced fractions, so a label reading "1 Oct"
     * is above the first of October and not two pixels off it.
     *
     * @return array<int, string>
     */
    public function ticks(): array
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

        $ticks = [];

        while ($cursor->lessThanOrEqualTo($this->to)) {
            // A bucket that starts before the window would be drawn off the left
            // edge; the window's own start already labels that end.
            if ($cursor->greaterThanOrEqualTo($this->from)) {
                $ticks[] = $cursor->toDateString();
            }

            $cursor = $cursor->add($step);
        }

        return $ticks;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        return $this->build()['rows'];
    }

    /**
     * Issues the filter matched that have no date at either end.
     *
     * Listed rather than drawn. There is no honest position for them on an axis made
     * of dates, and the alternative — inventing one from created_at or from the
     * estimate — produces a bar somebody will read as a plan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function undated(): array
    {
        return $this->build()['undated'];
    }

    /** Whether the LIMIT cut the result short. */
    public function truncated(): bool
    {
        return $this->build()['total'] > self::LIMIT;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array{rows: array<int, array<string, mixed>>, undated: array<int, array<string, mixed>>, total: int}
     */
    private function build(): array
    {
        if ($this->built !== null) {
            return $this->built;
        }

        $issues = $this->issues();
        $byId = $issues->keyBy('id');

        // Only children whose parent also survived the filter nest under it. One
        // whose parent did not is still real work and draws at the top level rather
        // than disappearing or dragging an unmatched parent back in.
        $childrenOf = $issues
            ->filter(fn (Issue $issue) => $issue->parent_id !== null && $byId->has($issue->parent_id))
            ->groupBy('parent_id');

        $extents = [];

        foreach ($issues as $issue) {
            $extents[$issue->id] = $this->effectiveExtent($issue, $childrenOf->get($issue->id) ?? collect());
        }

        // A parent and its children move together, so the sort is over groups rather
        // than over rows. Sorting the flat list would scatter children away from the
        // parent whose indent is the only thing that makes them children.
        $groups = [];

        foreach ($issues as $issue) {
            // Children are emitted underneath their parent, not in their own right.
            if ($issue->parent_id !== null && $byId->has($issue->parent_id)) {
                continue;
            }

            $kept = ($childrenOf->get($issue->id) ?? collect())
                ->filter(fn (Issue $child) => $extents[$child->id] !== null
                    && $this->overlapsWindow($extents[$child->id]))
                // One callable, not an array of them: Collection::sortBy treats a
                // callable inside an array as a *comparator* rather than as a value
                // to sort on, which silently sorts by nothing at all.
                ->sortBy(fn (Issue $child) => $extents[$child->id][0]->toDateString().' '.$child->key)
                ->values();

            // The parent is kept when it overlaps the window itself, or when any of
            // its children do: a parent that starts before the window with work
            // inside it is a row that has to be there for the indent to mean
            // anything.
            $keepParent = $extents[$issue->id] !== null
                && ($this->overlapsWindow($extents[$issue->id]) || $kept->isNotEmpty());

            $group = [];

            if ($keepParent) {
                $group[] = $this->row(
                    $issue,
                    $extents[$issue->id],
                    depth: 0,
                    childCount: ($childrenOf->get($issue->id) ?? collect())->count(),
                );
            }

            foreach ($kept as $child) {
                $group[] = $this->row($child, $extents[$child->id], depth: $keepParent ? 1 : 0, childCount: 0);
            }

            if ($group !== []) {
                $groups[] = $group;
            }
        }

        usort(
            $groups,
            fn (array $a, array $b) => [$a[0]['start'], $a[0]['key']] <=> [$b[0]['start'], $b[0]['key']],
        );

        $rows = array_merge(...($groups ?: [[]]));

        // Everything the filter matched with no date at either end, parents and
        // children alike. A parent standing in for its children still has an extent,
        // so it is not here.
        $undated = $issues
            ->filter(fn (Issue $issue) => $extents[$issue->id] === null)
            ->map(fn (Issue $issue) => $this->undatedRow($issue))
            ->values()
            ->all();

        $visible = collect($rows)->pluck('key')->all();

        // Connectors are resolved once the surviving rows are known: a dependency on
        // something that is not on screen has nowhere to draw to, and is reported as
        // a blocker rather than as a line.
        $rows = array_map(fn (array $row) => $this->withConnectors($row, $visible), $rows);

        return $this->built = [
            'rows' => $rows,
            'undated' => $undated,
            'total' => $issues->count() === self::LIMIT ? $this->countMatching() : $issues->count(),
        ];
    }

    /** @return Collection<int, Issue> */
    private function issues(): Collection
    {
        return $this->filtered()
            ->with([
                'status:id,name,category,color',
                'project:id,key,slug,name',
                'assignee:id,name',
                // Whole models rather than a column list: strict mode throws on
                // reading an attribute that was not selected, and a related issue is
                // read for its key, its title and both of its dates.
                'relations' => fn ($q) => $q
                    ->whereIn('type', [RelationType::Blocks->value, RelationType::BlockedBy->value])
                    ->with('relatedIssue'),
            ])
            ->orderBy('issues.id')
            ->limit(self::LIMIT)
            ->get();
    }

    private function countMatching(): int
    {
        return $this->filtered()->count();
    }

    /** @return Builder<Issue> */
    private function filtered(): Builder
    {
        return $this->filter->apply(Issue::query(), $this->query, $this->viewer);
    }

    /**
     * The window a row occupies, or null when it has none.
     *
     * A parent with dates of its own keeps them, even when its children run wider:
     * a date somebody typed is a commitment, and quietly widening it to fit the work
     * underneath is how a deadline stops meaning anything.
     *
     * @param  Collection<int, Issue>  $children
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function effectiveExtent(Issue $issue, Collection $children): ?array
    {
        $own = $this->ownExtent($issue);

        if ($own !== null) {
            return $own;
        }

        $childExtents = $children
            ->map(fn (Issue $child) => $this->ownExtent($child))
            ->filter()
            ->values();

        if ($childExtents->isEmpty()) {
            return null;
        }

        // Compared as ISO strings, which sort correctly and cannot be argued with.
        // Sorting the Carbon objects themselves falls back to PHP's property-by-
        // property object comparison, which is not a date comparison.
        return [
            CarbonImmutable::parse($childExtents->map(fn (array $e) => $e[0]->toDateString())->min()),
            CarbonImmutable::parse($childExtents->map(fn (array $e) => $e[1]->toDateString())->max()),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function ownExtent(Issue $issue): ?array
    {
        $start = $issue->start_on === null ? null : CarbonImmutable::parse($issue->start_on->toDateString());
        $due = $issue->due_on === null ? null : CarbonImmutable::parse($issue->due_on->toDateString());

        if ($start === null && $due === null) {
            return null;
        }

        $a = $start ?? $due;
        $b = $due ?? $start;

        // A start after its due date is a typo rather than a request to draw
        // backwards. The span is still the truth about which two dates are involved.
        return $a->lessThanOrEqualTo($b) ? [$a, $b] : [$b, $a];
    }

    /** @param array{0: CarbonImmutable, 1: CarbonImmutable} $extent */
    private function overlapsWindow(array $extent): bool
    {
        return $extent[0]->lessThanOrEqualTo($this->to) && $extent[1]->greaterThanOrEqualTo($this->from);
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $extent
     * @return array<string, mixed>
     */
    private function row(Issue $issue, array $extent, int $depth, int $childCount): array
    {
        $hasOwn = $this->ownExtent($issue) !== null;

        return [
            'key' => $issue->key,
            'title' => $issue->title,
            'project' => $issue->project->name,
            'status' => $issue->status->name,
            'assignee' => $issue->assignee?->name,
            // Sent back with a drag, so one made on top of somebody else's is refused.
            'version' => $issue->scheduleVersion(),
            'depth' => $depth,
            'start' => $extent[0]->toDateString(),
            'end' => $extent[1]->toDateString(),
            'start_on' => $issue->start_on?->toDateString(),
            'due_on' => $issue->due_on?->toDateString(),
            /*
             * How to draw it, which is the whole decision this class exists to make.
             *
             * - bar: both dates given, so the span is somebody's answer.
             * - milestone: one date given. A point, not a span — see the note on
             *   the class about what a missing start date is filled in with.
             * - rollup: no dates of its own, spanning its children. Drawn as a
             *   bracket so it does not read as a commitment of its own.
             */
            'kind' => match (true) {
                ! $hasOwn => 'rollup',
                $issue->start_on !== null && $issue->due_on !== null => 'bar',
                default => 'milestone',
            },
            'anchor' => $issue->due_on !== null ? 'due' : 'start',
            'open' => $issue->status->category->isOpen(),
            'overdue' => $this->isOverdue($issue),
            'children' => $childCount,
            'estimate' => $issue->estimate_minutes === null
                ? null
                : Duration::format($issue->estimate_minutes),
            'blocked_by' => $this->blockers($issue)->map(fn (Issue $b) => $b->key)->values()->all(),
            // Filled in once the visible set is known.
            'conflicts' => $this->blockers($issue)
                ->filter(fn (Issue $blocker) => $this->blocksInto($blocker, $extent[0]))
                ->map(fn (Issue $b) => $b->key)
                ->values()
                ->all(),
        ];
    }

    /**
     * Only a dependency that is actually in trouble gets a line.
     *
     * A connector for every `blocks` relation turns a busy quarter into a ball of
     * string, and most of those lines say something already obvious from the
     * ordering. A blocker that does not finish until after the thing it blocks has
     * started is the one that is worth interrupting somebody about.
     *
     * @param  array<int, string>  $visible
     * @return array<string, mixed>
     */
    private function withConnectors(array $row, array $visible): array
    {
        $row['conflicts'] = array_values(array_intersect($row['conflicts'], $visible));

        return $row;
    }

    /** @return Collection<int, Issue> */
    private function blockers(Issue $issue): Collection
    {
        return $issue->relations
            ->filter(fn ($relation) => $relation->type === RelationType::BlockedBy)
            ->map(fn ($relation) => $relation->relatedIssue)
            // The related issue can be gone: soft-deleted, or in a project that was.
            ->filter()
            ->values();
    }

    /** Whether a blocker's own end lands after the blocked row has started. */
    private function blocksInto(Issue $blocker, CarbonImmutable $start): bool
    {
        $extent = $this->ownExtent($blocker);

        return $extent !== null && $extent[1]->greaterThan($start);
    }

    /**
     * Past its due date and still open.
     *
     * The same rule as `is:overdue` in the query language, deliberately: two screens
     * disagreeing about what "late" means is worse than neither having it. Strictly
     * past, because something due today has until the end of the day, and an issue
     * nobody will work on again is finished rather than late.
     */
    private function isOverdue(Issue $issue): bool
    {
        return $issue->due_on !== null
            && $issue->status->category->isOpen()
            && $issue->due_on->toDateString() < CarbonImmutable::now()->toDateString();
    }

    /** @return array<string, mixed> */
    private function undatedRow(Issue $issue): array
    {
        return [
            'key' => $issue->key,
            'version' => $issue->scheduleVersion(),
            'title' => $issue->title,
            'project' => $issue->project->name,
            'status' => $issue->status->name,
            'assignee' => $issue->assignee?->name,
            'open' => $issue->status->category->isOpen(),
        ];
    }
}
