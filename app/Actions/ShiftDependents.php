<?php

namespace App\Actions;

use App\Enums\RelationType;
use App\Models\Issue;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * After a blocker moves later, move the work waiting on it later too — as far as it
 * has to go and no further.
 *
 * Only ever later. A blocker brought forward leaves its dependents where they are:
 * the room that opens up is the team's to use, and pulling work earlier on its own
 * is how somebody's carefully arranged week gets rearranged behind their back.
 *
 * The rule is the timeline's conflict rule, so a line that was red becomes a line
 * that is not: a dependent may start on the day its blocker ends, not before. Each
 * move goes through UpdateIssue and appears in that issue's activity. Closed work is
 * finished and does not move. Relating refuses loops, but the walk still remembers
 * where it has been, so data from before that rule cannot send it round for ever.
 */
class ShiftDependents
{
    public function __construct(private UpdateIssue $update) {}

    /** @return array<int, string> the keys of the issues moved, in the order moved */
    public function handle(Issue $blocker, ?User $actor = null): array
    {
        $moved = [];
        $seen = [$blocker->id => true];
        $queue = [$blocker->fresh()];

        while ($queue !== [] && count($seen) < 500) {
            $current = array_shift($queue);
            $end = $this->end($current);

            if ($end === null) {
                continue;
            }

            $dependents = Issue::whereIn('id', $current->relations()
                ->where('type', RelationType::Blocks->value)
                ->pluck('related_issue_id'))
                ->with('status')
                ->orderBy('id')
                ->get();

            foreach ($dependents as $dependent) {
                if (isset($seen[$dependent->id]) || ! $dependent->status->category->isOpen()) {
                    continue;
                }

                $seen[$dependent->id] = true;
                $start = $this->start($dependent);

                if ($start !== null && $end->greaterThan($start)) {
                    $days = (int) $start->diffInDays($end);

                    $this->update->handle($dependent, [
                        'start_on' => $dependent->start_on?->copy()->addDays($days)->toDateString(),
                        'due_on' => $dependent->due_on?->copy()->addDays($days)->toDateString(),
                        'because' => $current->key,
                    ], $actor);

                    $moved[] = $dependent->key;
                }

                // Onward either way: something further down may already be too close.
                $queue[] = $dependent->fresh();
            }
        }

        return $moved;
    }

    private function start(Issue $issue): ?CarbonImmutable
    {
        $date = $issue->start_on ?? $issue->due_on;

        return $date === null ? null : CarbonImmutable::parse($date->toDateString());
    }

    private function end(Issue $issue): ?CarbonImmutable
    {
        $date = $issue->due_on ?? $issue->start_on;

        return $date === null ? null : CarbonImmutable::parse($date->toDateString());
    }
}
