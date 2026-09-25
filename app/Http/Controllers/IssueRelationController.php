<?php

namespace App\Http\Controllers;

use App\Actions\MarkDuplicate;
use App\Actions\RelateIssues;
use App\Enums\RelationType;
use App\Models\Issue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class IssueRelationController extends Controller
{
    public function store(Request $request, Issue $issue, RelateIssues $action): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'type' => ['required', new Enum(RelationType::class)],
        ]);

        // Scoped, so a key from another workspace simply does not exist.
        $related = Issue::where('key', strtoupper($validated['key']))->first();

        if ($related === null) {
            return back()->withErrors(['key' => 'No issue with that key in this workspace.']);
        }

        $action->handle($issue, $related, RelationType::from($validated['type']), $request->user());

        return back();
    }

    public function destroy(Request $request, Issue $issue, RelateIssues $action): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'type' => ['required', new Enum(RelationType::class)],
        ]);

        $related = Issue::where('key', strtoupper($validated['key']))->firstOrFail();

        $action->remove($issue, $related, RelationType::from($validated['type']), $request->user());

        return back();
    }

    /**
     * Close this issue as a duplicate of another. Staff only: it closes the issue and
     * moves its followers, which is changing state.
     */
    public function duplicate(Request $request, Issue $issue, MarkDuplicate $action): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate(['key' => ['required', 'string', 'max:40']]);

        // Scoped, so a key from another workspace simply does not exist.
        $original = Issue::where('key', strtoupper(trim($validated['key'])))->first();

        if ($original === null) {
            return back()->withErrors(['key' => 'No issue with that key in this workspace.']);
        }

        $action->handle($issue, $original, $request->user());

        $leftOut = $action->leftOut === []
            ? ''
            : ' '.implode(', ', $action->leftOut)." cannot see {$original->key}, so "
                .(count($action->leftOut) === 1 ? 'was' : 'were')
                .' not added as a watcher. Share it with them from its sidebar if they should follow it.';

        return back()->with('success', "{$issue->key} closed as a duplicate of {$original->key}. Its followers now follow {$original->key}.{$leftOut}");
    }
}
