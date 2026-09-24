<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\RelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relations are stored from both sides, so "blocked by" is answerable without a
 * union query on every issue page.
 */
class RelateIssues
{
    public function handle(
        Issue $issue,
        Issue $related,
        RelationType $type,
        ?User $actor = null,
    ): void {
        abort_if($issue->is($related), 422, 'An issue cannot relate to itself.');

        // A loop of blockers can never be started: each waits on the next. Refused
        // here rather than on the timeline alone, so the issue page cannot make one.
        [$blocker, $blocked] = match ($type) {
            RelationType::Blocks => [$issue, $related],
            RelationType::BlockedBy => [$related, $issue],
            default => [null, null],
        };

        if ($blocker !== null && self::blocks($blocked, $blocker)) {
            throw ValidationException::withMessages([
                'key' => "{$blocked->key} already waits on {$blocker->key}, so {$blocker->key} cannot wait on it too.",
            ]);
        }

        DB::transaction(function () use ($issue, $related, $type, $actor) {
            IssueRelation::updateOrCreate([
                'issue_id' => $issue->id,
                'related_issue_id' => $related->id,
                'type' => $type->value,
            ]);

            IssueRelation::updateOrCreate([
                'issue_id' => $related->id,
                'related_issue_id' => $issue->id,
                'type' => $type->inverse()->value,
            ]);

            $issue->recordEvent(IssueEventType::Related, [
                'type' => $type->value,
                'key' => $related->key,
                'title' => $related->title,
            ], $actor);
        });
    }

    /**
     * Whether $from blocks $to, directly or through a chain of other issues.
     *
     * Walked through the table rather than loaded models, because a chain can pass
     * through issues in projects the caller never loaded. Bounded, since a
     * pathological graph should cost a refusal rather than a timeout.
     */
    public static function blocks(Issue $from, Issue $to): bool
    {
        $seen = [$from->id => true];
        $frontier = [$from->id];

        for ($depth = 0; $frontier !== [] && $depth < 100; $depth++) {
            $next = IssueRelation::whereIn('issue_id', $frontier)
                ->where('type', RelationType::Blocks->value)
                ->pluck('related_issue_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (in_array($to->id, $next, true)) {
                return true;
            }

            $frontier = array_values(array_filter($next, fn (int $id) => ! isset($seen[$id])));

            foreach ($frontier as $id) {
                $seen[$id] = true;
            }
        }

        return false;
    }

    public function remove(Issue $issue, Issue $related, RelationType $type, ?User $actor = null): void
    {
        DB::transaction(function () use ($issue, $related, $type, $actor) {
            IssueRelation::where('issue_id', $issue->id)
                ->where('related_issue_id', $related->id)
                ->where('type', $type->value)
                ->delete();

            IssueRelation::where('issue_id', $related->id)
                ->where('related_issue_id', $issue->id)
                ->where('type', $type->inverse()->value)
                ->delete();

            $issue->recordEvent(IssueEventType::Unrelated, [
                'type' => $type->value,
                'key' => $related->key,
            ], $actor);
        });
    }
}
