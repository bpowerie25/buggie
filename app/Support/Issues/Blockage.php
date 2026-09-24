<?php

namespace App\Support\Issues;

use App\Models\Issue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many days one open blocker is holding up one piece of work waiting on it.
 *
 * The timeline's conflict rule, with one addition. The rule: the work waiting can
 * start on the day its blocker ends, not before, so a blocker ending after that start
 * is in the way by the difference. The addition: an open blocker whose date has
 * passed, or that has no date at all, cannot end before today. A blocker a week late
 * is delaying everything planned to start this week, even though its bar says it
 * finished last Tuesday.
 *
 * A closed blocker delays nothing any more. Work with no date to start from cannot
 * be late for its start. `is:delaying` asks the same question in SQL (see sql());
 * the two are tested against each other.
 */
final class Blockage
{
    /** Days $blocker is holding up $waiting, or 0 when it is not. */
    public static function days(Issue $blocker, Issue $waiting): int
    {
        if (! $blocker->status->category->isOpen() || ! $waiting->status->category->isOpen()) {
            return 0;
        }

        $start = $waiting->start_on ?? $waiting->due_on;

        if ($start === null) {
            return 0;
        }

        $today = CarbonImmutable::today();
        $own = $blocker->due_on ?? $blocker->start_on;
        $end = $own === null ? $today : CarbonImmutable::parse($own->toDateString())->max($today);
        $start = CarbonImmutable::parse($start->toDateString());

        return $end->greaterThan($start) ? (int) $start->diffInDays($end) : 0;
    }

    /**
     * Constrains a query over the *waiting* issue, inside a whereHas on the blocker's
     * `relations`, to work its blocker is delaying. The blocker is reached through
     * issue_relations.issue_id, because both ends of the link are rows in `issues`
     * and an unqualified column there would be ambiguous.
     */
    public static function sql(Builder $waiting): Builder
    {
        return $waiting->whereRaw(
            'COALESCE(issues.start_on, issues.due_on) < ('
            .'SELECT GREATEST(COALESCE(b.due_on, b.start_on, CURRENT_DATE), CURRENT_DATE) '
            .'FROM issues AS b WHERE b.id = issue_relations.issue_id)'
        );
    }
}
