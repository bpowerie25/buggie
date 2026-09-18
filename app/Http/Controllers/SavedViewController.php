<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use App\Support\Issues\IssueQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SavedView::class);

        $data = $this->validated($request);

        $view = SavedView::create([
            ...$data,
            'user_id' => $request->boolean('shared') ? null : $request->user()->id,
            'created_by_id' => $request->user()->id,
            'position' => (int) SavedView::max('position') + 1,
        ]);

        return redirect("/issues?q=".urlencode($view->query))
            ->with('success', "View “{$view->name}” saved.");
    }

    public function update(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->authorize('update', $savedView);

        $savedView->update($this->validated($request));

        return back()->with('success', 'View updated.');
    }

    public function destroy(SavedView $savedView): RedirectResponse
    {
        $this->authorize('delete', $savedView);

        $savedView->delete();

        return back()->with('success', 'View deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'query' => ['nullable', 'string', 'max:500'],
            'layout' => ['required', Rule::in(['list', 'board'])],
            'group_by' => ['required', Rule::in(['status', 'assignee', 'priority', 'project'])],
        ]);

        // Store the canonical form, so two spellings of the same view compare equal.
        $data['query'] = (string) IssueQuery::parse($data['query'] ?? '');

        return $data;
    }
}
