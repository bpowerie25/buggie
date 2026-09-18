<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkspaceSettingsController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function edit(): Response
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('update', $workspace);

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'created_at' => $workspace->created_at->toDateString(),
            ],
            'domain' => config('buggy.domain'),
            'can_delete' => request()->user()->can('delete', $workspace),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        // The slug is deliberately not editable: it is in every widget snippet, every
        // invitation link and every bookmark our customers' customers hold.
        $workspace->update($validated);

        return back()->with('success', 'Workspace updated.');
    }

    public function destroy(Request $request): SymfonyResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('delete', $workspace);

        $request->validate([
            'confirm' => ['required', 'in:'.$workspace->slug],
        ], ['confirm.in' => 'Type the workspace address to confirm.']);

        $workspace->delete();

        $request->session()->flash('success', 'Workspace deleted.');

        return redirect_across_domains(central_url('/'));
    }
}
