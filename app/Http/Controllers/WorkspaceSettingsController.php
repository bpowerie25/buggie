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
            'domain' => config('buggie.domain'),
            'can_delete' => request()->user()->can('delete', $workspace),

            // Only this person's own tokens. An admin seeing a colleague's token
            // names is a small thing, but there is no reason for it.
            'tokens' => \App\Models\ApiToken::query()
                ->where('workspace_id', $workspace->id)
                ->where('tokenable_id', request()->user()->id)
                ->latest()
                ->get()
                ->map(fn (\App\Models\ApiToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toDateString(),
                    'created_at' => $token->created_at->toDateString(),
                ]),
            'abilities' => \App\Http\Controllers\ApiTokenController::ABILITIES,

            'webhooks' => \App\Models\Webhook::with(['project:id,name'])
                ->withCount('deliveries')
                ->latest()
                ->get()
                ->map(fn (\App\Models\Webhook $webhook) => [
                    'id' => $webhook->id,
                    'name' => $webhook->name,
                    'url' => $webhook->url,
                    'project' => $webhook->project?->name,
                    'events' => $webhook->events,
                    'is_active' => $webhook->is_active,
                    'last_delivered_at' => $webhook->last_delivered_at?->toIso8601String(),
                    // The last handful only: enough to answer "is it working?"
                    // without turning a settings page into a log viewer.
                    'deliveries' => $webhook->deliveries()->limit(5)->get()
                        ->map(fn ($delivery) => [
                            'event' => $delivery->event,
                            'status' => $delivery->status,
                            'error' => $delivery->error,
                            'ok' => $delivery->succeeded(),
                            'at' => $delivery->created_at?->toIso8601String(),
                        ]),
                ]),
            'webhookEvents' => \App\Enums\WebhookEvent::options(),
            'projects' => \App\Models\Project::active()->orderBy('name')->get(['id', 'name']),
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
