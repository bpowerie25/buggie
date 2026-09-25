<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\IssueVisibility;
use App\Enums\RelationType;
use App\Enums\StatusCategory;
use App\Enums\WatchReason;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Close an issue as a duplicate of another, and send everybody following it there.
 *
 * A "duplicates" relation already existed, but it was only a link: the duplicate
 * stayed open, and whoever reported it went on waiting on an issue nobody would
 * work. Marking one as a duplicate now links the two, closes the duplicate as not
 * done — closed, not resolved, since nothing was fixed there — says so in its
 * thread, and makes its reporter and watchers watchers of the original, so a
 * client who raised it hears when the real one moves.
 *
 * Always points at the original: marking an issue as a duplicate of something that
 * is itself a duplicate follows the chain, so there is never a duplicate of a
 * duplicate to click through.
 */
class MarkDuplicate
{
    public function __construct(
        private RelateIssues $relate,
        private UpdateIssue $updates,
    ) {}

    /** @var array<int, string> who could not be moved onto the original, from the last call */
    public array $leftOut = [];

    public function handle(Issue $duplicate, Issue $original, User $actor): Issue
    {
        $this->leftOut = [];

        $original = $this->canonical($original);

        if ($original->is($duplicate)) {
            throw ValidationException::withMessages([
                'key' => 'An issue cannot be a duplicate of itself, or of an issue that is already its duplicate.',
            ]);
        }

        return DB::transaction(function () use ($duplicate, $original, $actor) {
            $this->relate->handle($duplicate, $original, RelationType::Duplicates, $actor);

            $duplicate->forceFill(['duplicate_of_id' => $original->id])->save();

            // Anything that already pointed here now points at the original.
            Issue::where('duplicate_of_id', $duplicate->id)->update(['duplicate_of_id' => $original->id]);

            $duplicate->loadMissing(['watchers', 'reporter']);

            foreach (collect($duplicate->watchers)->push($duplicate->reporter)->filter()->unique('id') as $person) {
                // Only somebody who can already open the original. Watching an issue is
                // itself a way in for a client, so adding one who cannot would hand a
                // client-tier user another client's issue — its thread, their name.
                // The team is told who was left out, and can share it on purpose.
                if (Gate::forUser($person)->allows('view', $original)) {
                    $original->watch($person, WatchReason::Duplicate);
                } else {
                    $this->leftOut[] = $person->name;
                }
            }

            // Said in the thread, in public when a client can see the issue: it is
            // the answer to "what happened to my report?". The original is named only
            // where a client of this project could open it — an internal issue, or
            // one in another project, is not something to tell them the key of.
            // And never to a client who cannot open it: its key and title in a public
            // comment would be another client's work described to them.
            $nameable = $original->visibility === IssueVisibility::Client
                && $original->project_id === $duplicate->project_id
                && $this->leftOut === [];

            $text = $nameable
                ? "Closed as a duplicate of {$original->key} ({$original->title}). Follow {$original->key} for progress."
                : 'Closed as a duplicate of an issue the team is already working on. You will hear from us there.';
            $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];

            $duplicate->comments()->create([
                'user_id' => $actor->id,
                'body' => $body,
                'body_text' => TiptapDocument::toPlainText($body),
                'is_internal' => $duplicate->visibility !== IssueVisibility::Client,
                'source' => 'web',
            ]);

            $closed = $this->closedStatus($duplicate);

            if ($closed !== null && $duplicate->loadMissing('status')->status->category->isOpen()) {
                $this->updates->moveTo($duplicate, $closed, $actor, IssueEventType::MarkedDuplicate, [
                    'of' => $nameable ? $original->key : null,
                ]);
            } else {
                $duplicate->recordEvent(IssueEventType::MarkedDuplicate, ['of' => $nameable ? $original->key : null], $actor, isInternal: false);
            }

            return $duplicate->fresh();
        });
    }

    /** Follow duplicate_of to the issue at the end of the chain, stopping at a loop. */
    private function canonical(Issue $issue): Issue
    {
        $seen = [$issue->id];

        while ($issue->duplicate_of_id !== null && ! in_array($issue->duplicate_of_id, $seen, true)) {
            $next = Issue::find($issue->duplicate_of_id);

            if ($next === null) {
                break;
            }

            $seen[] = $next->id;
            $issue = $next;
        }

        return $issue;
    }

    /** Not done: the first canceled status, or failing that the first done one. */
    private function closedStatus(Issue $issue): ?Status
    {
        return $issue->project->statuses()->where('category', StatusCategory::Canceled->value)->orderBy('position')->first()
            ?? $issue->project->statuses()->where('category', StatusCategory::Done->value)->orderBy('position')->first();
    }
}
