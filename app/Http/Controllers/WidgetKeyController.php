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
        $key = WidgetKey::create([
            'project_id' => $project->id,
            'allowed_origins' => $project->defaultWidgetOrigins(),
            'mode' => 'identified',
        ]);

        return $this->reveal($key, $key->secret, 'Widget key created. Copy its secret now — it is not shown again.');
    }

    public function update(Request $request, WidgetKey $widgetKey): RedirectResponse
    {
        $this->authorize('update', $widgetKey->project);

        $validated = $request->validate([
            'allowed_origins' => ['array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'mode' => ['required', Rule::enum(\App\Enums\WidgetMode::class)],
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

    /** A new secret; the old one stops verifying at once. */
    public function rotate(WidgetKey $widgetKey): RedirectResponse
    {
        $this->authorize('update', $widgetKey->project);

        return $this->reveal($widgetKey, $widgetKey->rotateSecret(), 'New secret created. Copy it now — it is not shown again, and the old one no longer works.');
    }

    /**
     * The one time a secret leaves the server: flashed to the person who created or
     * rotated it, for the next page only. It is never in the settings page's props
     * otherwise, nor in the embed script, nor in any API response.
     */
    private function reveal(WidgetKey $key, string $secret, string $message): RedirectResponse
    {
        return back()
            ->with('success', $message)
            ->with('widget_secret', ['key' => $key->public_key, 'secret' => $secret]);
    }

    public function destroy(WidgetKey $widgetKey): RedirectResponse
    {
        $this->authorize('delete', $widgetKey->project);

        $widgetKey->delete();

        return back()->with('success', 'Widget key revoked.');
    }
}
