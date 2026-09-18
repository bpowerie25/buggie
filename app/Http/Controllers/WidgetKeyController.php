<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WidgetKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WidgetKeyController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        // Inherits the project's site URL rather than starting open. An empty
        // allowlist accepts reports from any origin, so a key created and forgotten
        // is a key anyone who reads the page source can post to.
        WidgetKey::create([
            'project_id' => $project->id,
            'allowed_origins' => $project->defaultWidgetOrigins(),
            'mode' => 'identified',
        ]);

        return back()->with('success', 'Widget key created.');
    }

    public function update(Request $request, WidgetKey $widgetKey): RedirectResponse
    {
        $this->authorize('update', $widgetKey->project);

        $validated = $request->validate([
            'allowed_origins' => ['array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'mode' => ['required', Rule::in(['identified', 'anonymous'])],
            'require_email' => ['boolean'],
            'capture_screenshot' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $validated['allowed_origins'] = array_values(array_filter(
            array_map('trim', $validated['allowed_origins'] ?? []),
        ));

        $widgetKey->update($validated);

        return back()->with('success', 'Widget settings saved.');
    }

    public function destroy(WidgetKey $widgetKey): RedirectResponse
    {
        $this->authorize('delete', $widgetKey->project);

        $widgetKey->delete();

        return back()->with('success', 'Widget key revoked.');
    }
}
