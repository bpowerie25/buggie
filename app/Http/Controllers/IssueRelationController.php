<?php

namespace App\Http\Controllers;

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
}
