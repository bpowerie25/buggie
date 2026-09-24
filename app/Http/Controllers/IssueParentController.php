<?php

namespace App\Http\Controllers;

use App\Actions\SetParent;
use App\Models\Issue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Setting and clearing an issue's parent.
 *
 * Its own controller rather than another arm of UpdateIssue, because the rules that
 * keep the hierarchy one level deep have nothing to do with editing an issue and
 * everything to do with the shape of the tree. They live in SetParent.
 */
class IssueParentController extends Controller
{
    public function __invoke(Request $request, Issue $issue, SetParent $action): RedirectResponse
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
            $action->handle($issue, null);

            return back()->with('success', "{$issue->key} is no longer a subtask.");
        }

        $parent = Issue::where('key', $key)->firstOrFail();

        $action->handle($issue, $parent);

        return back()->with('success', "{$issue->key} is now part of {$parent->key}.");
    }
}
