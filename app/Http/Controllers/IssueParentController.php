<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Setting and clearing an issue's parent.
 *
 * Its own controller rather than another arm of UpdateIssue, because the rules that
 * keep the hierarchy one level deep have nothing to do with editing an issue and
 * everything to do with the shape of the tree.
 */
class IssueParentController extends Controller
{
    public function __invoke(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate([
            // Scoped by hand: validation rules do not see the workspace scope, and an
            // unscoped exists() would accept a key from somebody else's workspace and
            // reveal that it exists.
            'parent' => [
                'nullable', 'string',
                Rule::exists('issues', 'key')
                    ->where('workspace_id', $issue->workspace_id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $key = $validated['parent'] ?? null;

        if ($key === null) {
            $issue->forceFill(['parent_id' => null])->save();

            return back()->with('success', "{$issue->key} is no longer a subtask.");
        }

        $parent = Issue::where('key', $key)->firstOrFail();

        $this->guard($issue, $parent);

        $issue->forceFill(['parent_id' => $parent->id])->save();

        return back()->with('success', "{$issue->key} is now part of {$parent->key}.");
    }

    /**
     * The three ways a one-level hierarchy breaks.
     *
     * All refused here rather than in the interface, because the API and any future
     * importer reach this too, and a tree that is wrong is far harder to repair than
     * a request that was refused.
     */
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
        if (! $issue->canHaveParent()) {
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
