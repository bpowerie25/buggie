<?php

namespace App\Actions;

use App\Models\Issue;
use Illuminate\Validation\ValidationException;

/**
 * Make an issue a subtask of another, or stop it being one.
 *
 * The hierarchy is one level deep. The rules that keep it that way are here rather
 * than in any one screen, because the issue page, a new subtask and the API all
 * reach this, and a tree that is wrong is far harder to repair than a request that
 * was refused.
 */
class SetParent
{
    public function handle(Issue $issue, ?Issue $parent): void
    {
        if ($parent !== null) {
            $this->guard($issue, $parent);
        }

        $issue->forceFill(['parent_id' => $parent?->id])->save();
    }

    /** The four ways a one-level hierarchy breaks. */
    private function guard(Issue $issue, Issue $parent): void
    {
        if ($parent->id === $issue->id) {
            throw ValidationException::withMessages([
                'parent' => 'An issue cannot be its own parent.',
            ]);
        }

        // The parent is already somebody's child, so accepting would make three
        // levels.
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent' => "{$parent->key} is already a subtask, and subtasks do not nest.",
            ]);
        }

        // This issue has children of its own, so it cannot become a child.
        if ($issue->exists && ! $issue->canHaveParent()) {
            throw ValidationException::withMessages([
                'parent' => "{$issue->key} has subtasks of its own, so it cannot become one.",
            ]);
        }

        // Crossing projects would put a subtask in a board its parent never appears
        // on, and the issue keys would disagree about which project the work is in.
        if ($parent->project_id !== $issue->project_id) {
            throw ValidationException::withMessages([
                'parent' => 'A subtask and its parent have to be in the same project.',
            ]);
        }
    }
}
