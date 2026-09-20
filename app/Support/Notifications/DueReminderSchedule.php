<?php

namespace App\Support\Notifications;

/**
 * Which days an issue gets chased about its due date, and what the reminder says.
 *
 * ## The problem this exists to solve
 *
 * The obvious implementation — "every day, email about everything overdue" — makes an
 * issue that is three weeks late produce twenty-one identical emails. The recipient
 * filters the sender, and from then on the tracker cannot reach them about anything.
 * Chasing has to get quieter as it goes on or it stops working.
 *
 * ## The policy
 *
 * Reminders land on an escalating ladder, measured in whole days from the due date:
 *
 *     -3  due in three days      still time to move it or do it
 *     -1  due tomorrow           the last useful warning
 *      0  due today
 *     +1  one day late           the one that actually gets acted on
 *     +3
 *     +7
 *     then every seventh day: 14, 21, 28, …
 *
 * Nothing before three days out: a bug tracker is not a calendar, and a warning a
 * fortnight ahead is noise by the time it matters. Weekly for ever after the first
 * week, rather than stopping: an issue still open and still overdue has not stopped
 * being late, and going silent would turn a missed deadline into a forgotten one.
 *
 * ## Why this shape, rather than a "last chased" interval
 *
 * Every reminder day here is a pure function of today and the due date. Two runs on
 * the same day therefore agree, a run that is skipped because the scheduler was down
 * simply misses that rung instead of firing a backlog of catch-up mail, and nothing
 * has to be stored except which day was last handled. `issues.due_reminded_on` is
 * that, and it is the whole idempotence mechanism — see ChaseDueIssues.
 *
 * The cost of the pure-function approach is the skipped rung: a scheduler that was
 * down for the whole of day +1 never sends the day +1 reminder, because on day +2
 * nothing is owed. That is the right trade. The alternative — remembering the last
 * rung reached and catching up — means an install that was offline for a month
 * delivers a month of chasing the moment it comes back, which is precisely the
 * failure this policy is here to avoid.
 */
final class DueReminderSchedule
{
    /**
     * The furthest ahead of a due date anything is ever sent.
     *
     * Kept here so the database prefilter in ChaseDueIssues and the ladder below
     * cannot drift apart: widen the ladder without widening the query and the new
     * rung is silently never reached.
     */
    public const EARLIEST_OFFSET = -3;

    /** Rungs in the first week either side; after that it is weekly. */
    private const RUNGS = [-3, -1, 0, 1, 3, 7];

    /**
     * Is today a day this issue should be chased?
     *
     * @param  int  $offset  Whole days from the due date: negative before, 0 on the
     *                       day, positive once overdue.
     */
    public static function isReminderDay(int $offset): bool
    {
        if ($offset <= 7) {
            return in_array($offset, self::RUNGS, true);
        }

        return $offset % 7 === 0;
    }

    /** The line that appears in the digest. Written to be readable on a phone. */
    public static function sentence(int $offset): string
    {
        return match (true) {
            $offset < -1 => 'This is due in '.abs($offset).' days.',
            $offset === -1 => 'This is due tomorrow.',
            $offset === 0 => 'This is due today.',
            $offset === 1 => 'This was due yesterday.',
            default => "This is {$offset} days overdue.",
        };
    }
}
