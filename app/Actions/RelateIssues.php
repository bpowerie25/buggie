<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\RelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
