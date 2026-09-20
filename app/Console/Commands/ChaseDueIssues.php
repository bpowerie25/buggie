<?php

namespace App\Console\Commands;

use App\Enums\NotificationReason;
use App\Models\Issue;
use App\Models\User;
use App\Support\Notifications\DueReminderSchedule;
use App\Support\Notifications\Notifier;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Chases due dates: tells the assignee and the watchers when an issue is nearly due,
 * and keeps telling them, more quietly, while it is late.
 *
 * Until this existed `due_on` was decoration — a field you could set and which then
 * did nothing, which is worse than not having it, because people set it and believe
 * something is watching.
 *
 * ## Idempotence
 *
 * This is the whole difficulty. Three things make a second run of the day a no-op:
 *
 * 1. **Which days are reminder days is a pure function** of today and the due date —
 *    see DueReminderSchedule. No rung is "the next one", so two runs agree.
 * 2. **`issues.due_reminded_on` is the high-water mark.** An issue already stamped
 *    with today is excluded in SQL, so a retry, a manual run, or two schedulers
 *    racing each other cost one query and send nothing.
 * 3. **Recording and stamping happen in one transaction.** A crash between them would
 *    otherwise leave pending rows with no stamp, and the retry would duplicate them.
 *
 * The stamp is written even when the issue turns out to have nobody to tell: the
 * question it answers is "has today been dealt with", not "did anyone hear".
 *
 * ## Dates, not times
 *
 * `due_on` is a date and the application runs in UTC, so "due today" means the stored
 * date equals today's date in UTC, and offsets are whole days between two midnights.
 * Nothing here compares a date to `now()`, which is the classic way to get a reminder
 * at 00:00 and a second one at 23:59 the same day.
 *
 * ## Tenancy
 *
 * This runs from the scheduler, outside any tenant context. Candidates are gathered
 * with tenancy explicitly off — including for the relations, which is why it is
 * `withoutTenancy()` around the sweep rather than `acrossAllWorkspaces()` alone —
 * and everything with a consequence then happens inside `Tenancy::run()` for the
 * issue's own workspace, so recorded notifications are stamped with the right
 * workspace_id and the policy resolves against the right membership.
 */
class ChaseDueIssues extends Command
{
    protected $signature = 'issues:chase-due
        {--dry-run : Report who would be told without recording anything}';

    protected $description = 'Notify assignees and watchers about issues due soon or overdue';

    /** Kept small: each issue is a handful of queries and this runs once a day. */
    private const CHUNK = 100;

    public function handle(Tenancy $tenancy, Notifier $notifier): int
    {
        $today = Carbon::now()->startOfDay();
        $dry = (bool) $this->option('dry-run');

        $chased = 0;
        $told = 0;

        $tenancy->withoutTenancy(function () use ($tenancy, $notifier, $today, $dry, &$chased, &$told) {
            // chunkById, not get(): a busy install has more issues than fit in memory,
            // and ordering by id means the stamp written below cannot shift the page
            // under us the way an offset would.
            $this->candidates($today)->chunkById(self::CHUNK, function ($issues) use ($tenancy, $notifier, $today, $dry, &$chased, &$told) {
                foreach ($issues as $issue) {
                    $offset = $this->offsetInDays($issue, $today);

                    if (! DueReminderSchedule::isReminderDay($offset)) {
                        continue;
                    }

                    $chased++;
                    $told += $tenancy->run(
                        $issue->workspace,
                        fn () => $this->chase($issue, $offset, $today, $notifier, $dry),
                    );
                }
            });
        });

        $this->line(sprintf('%-24s %d', 'issues chased', $chased));
        $this->line(sprintf('%-24s %d', 'people told', $told));

        if ($dry) {
            $this->comment('Dry run — nothing was recorded and no issue was marked as chased.');
        }

        return self::SUCCESS;
    }

    /**
     * Issues that could possibly be due a reminder today.
     *
     * Deliberately wider than the ladder: SQL narrows it to the issues worth loading
     * and DueReminderSchedule decides. Putting the ladder in SQL would mean writing
     * it twice.
     *
     * @return Builder<Issue>
     */
    private function candidates(Carbon $today): Builder
    {
        // Nothing further ahead than the first rung can be due a reminder today.
        $horizon = $today->copy()->addDays(-DueReminderSchedule::EARLIEST_OFFSET);

        return Issue::query()
            ->acrossAllWorkspaces()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $horizon->toDateString())
            // The high-water mark. `<` today rather than `!=`, so a clock that went
            // backwards cannot re-open a day that has already been sent.
            ->where(fn (Builder $q) => $q
                ->whereNull('due_reminded_on')
                ->orWhereDate('due_reminded_on', '<', $today->toDateString()))
            // Never chase finished work. By category, never by name: a project is free
            // to call its done column "Shipped" or its cancelled one "Not doing".
            ->open()
            // An archived project is one somebody decided to stop working on. Its
            // deadlines stopped meaning anything at the same moment.
            ->whereHas('project', fn (Builder $q) => $q->where('is_archived', false))
            ->with(['workspace', 'project', 'status', 'assignee', 'watchers']);
    }

    /** Whole days from the due date: negative before it, 0 on it, positive after. */
    private function offsetInDays(Issue $issue, Carbon $today): int
    {
        // Through the date string, so a cast that ever produced something other than
        // midnight could not turn "due today" into "due yesterday, by an hour".
        $due = Carbon::parse($issue->due_on->toDateString())->startOfDay();

        return (int) $due->diffInDays($today, absolute: false);
    }

    /** @return int How many people were told. */
    private function chase(Issue $issue, int $offset, Carbon $today, Notifier $notifier, bool $dry): int
    {
        $recipients = $this->recipients($issue);

        if ($dry) {
            return $recipients->count();
        }

        return DB::transaction(function () use ($issue, $offset, $today, $notifier, $recipients) {
            foreach ($recipients as $recipient) {
                $notifier->record($recipient, $issue, NotificationReason::DueDate, data: [
                    'days' => $offset,
                    'due_on' => $issue->due_on->toDateString(),
                ]);
            }

            // Straight to the query builder: this is bookkeeping, not activity, and
            // moving updated_at would push the issue to the top of a list sorted by
            // it and read as though somebody had touched the work.
            DB::table('issues')
                ->where('id', $issue->id)
                ->update(['due_reminded_on' => $today->toDateString()]);

            return $recipients->count();
        });
    }

    /**
     * Who hears about it: whoever holds the issue, and whoever is watching.
     *
     * The assignee is included even if they are not a watcher. Unwatching is a
     * deliberate "spare me the running commentary" — see Issue::unwatch — and a
     * deadline on work in your name is not commentary. Somebody who wants none of
     * this turns the reason off, which stops it everywhere at once.
     *
     * Every recipient is put through the issue policy rather than through a visibility
     * check written here. A client watching a client-visible issue in a project they
     * hold is fine; a client watching anything else is a leak, and so is a watcher who
     * has since been removed from the workspace. The policy is the gate the HTTP layer
     * uses and it is tested adversarially; a second copy of those rules would be one
     * more place for them to drift.
     *
     * @return Collection<int, User>
     */
    private function recipients(Issue $issue): Collection
    {
        return collect([$issue->assignee])
            ->concat($issue->watchers)
            ->filter()
            ->unique('id')
            ->filter(fn (User $user) => Gate::forUser($user)->allows('view', $issue))
            ->values();
    }
}
