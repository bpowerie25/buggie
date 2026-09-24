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
                'show_staff_names' => \App\Support\Issues\AuthorLabel::showsStaffNames($workspace),
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

            'chatIntegrations' => \App\Models\ChatIntegration::with(['project:id,name'])
                ->latest()
                ->get()
                ->map(fn (\App\Models\ChatIntegration $integration) => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'provider' => $integration->provider->value,
                    'provider_label' => $integration->provider->label(),
                    'project' => $integration->project?->name,
                    'events' => $integration->events,
                    'is_active' => $integration->is_active,
                    'internal_activity' => $integration->internal_activity,
                    'last_delivered_at' => $integration->last_delivered_at?->toIso8601String(),

                    // Never the address itself. Whoever holds it can post into that
                    // channel as us, and whether one is set is all the form needs to
                    // know — the same rule as the SMTP password.
                    'has_url' => $integration->hasUrl(),

                    'deliveries' => $integration->deliveries()->limit(5)->get()
                        ->map(fn (\App\Models\ChatDelivery $delivery) => [
                            'event' => $delivery->event,
                            'status' => $delivery->status,
                            'error' => $delivery->error,
                            'ok' => $delivery->succeeded(),
                            'at' => $delivery->created_at?->toIso8601String(),
                        ]),
                ]),
            'chatProviders' => \App\Enums\ChatProvider::options(),

            'projects' => \App\Models\Project::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'show_staff_names' => ['sometimes', 'boolean'],
        ]);

        // The slug is deliberately not editable: it is in every widget snippet, every
        // invitation link and every bookmark our customers' customers hold.
        $workspace->update(['name' => $validated['name']]);

        if (array_key_exists('show_staff_names', $validated)) {
            $workspace->forceFill(['settings' => [
                ...($workspace->settings ?? []),
                \App\Support\Issues\AuthorLabel::SHOW_STAFF_NAMES => (bool) $validated['show_staff_names'],
            ]])->save();
        }

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
