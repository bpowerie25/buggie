<?php

namespace App\Http\Controllers;

use App\Enums\ChatProvider;
use App\Enums\WebhookEvent;
use App\Models\ApiToken;
use App\Models\ChatDelivery;
use App\Models\ChatIntegration;
use App\Models\Project;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\Issues\AuthorLabel;
use App\Support\Reports\ReporterLink;
use App\Support\Settings\Features;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
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
                'show_staff_names' => AuthorLabel::showsStaffNames($workspace),
                'trust_unverified_emails' => ReporterLink::trustsUnverified($workspace),
                'features' => Features::all($workspace),
                'members_manage_labels' => Features::membersManageLabels($workspace),
                'created_at' => $workspace->created_at->toDateString(),
            ],
            'domain' => config('buggie.domain'),
            'can_delete' => request()->user()->can('delete', $workspace),

            // Only this person's own tokens. An admin seeing a colleague's token
            // names is a small thing, but there is no reason for it.
            'tokens' => ApiToken::query()
                ->where('workspace_id', $workspace->id)
                ->where('tokenable_id', request()->user()->id)
                ->latest()
                ->get()
                ->map(fn (ApiToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toDateString(),
                    'created_at' => $token->created_at->toDateString(),
                ]),
            'abilities' => ApiTokenController::ABILITIES,

            'webhooks' => Webhook::with(['project:id,name'])
                ->withCount('deliveries')
                ->latest()
                ->get()
                ->map(fn (Webhook $webhook) => [
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
            'webhookEvents' => WebhookEvent::options(),

            'chatIntegrations' => ChatIntegration::with(['project:id,name'])
                ->latest()
                ->get()
                ->map(fn (ChatIntegration $integration) => [
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
                        ->map(fn (ChatDelivery $delivery) => [
                            'event' => $delivery->event,
                            'status' => $delivery->status,
                            'error' => $delivery->error,
                            'ok' => $delivery->succeeded(),
                            'at' => $delivery->created_at?->toIso8601String(),
                        ]),
                ]),
            'chatProviders' => ChatProvider::options(),

            'projects' => Project::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'show_staff_names' => ['sometimes', 'boolean'],
            'trust_unverified_emails' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'members_manage_labels' => ['sometimes', 'boolean'],
        ]);

        // The slug is deliberately not editable: it is in every widget snippet, every
        // invitation link and every bookmark our customers' customers hold.
        $workspace->update(['name' => $validated['name']]);

        $settings = collect([
            AuthorLabel::SHOW_STAFF_NAMES => 'show_staff_names',
            ReporterLink::TRUST_UNVERIFIED => 'trust_unverified_emails',
        ])->filter(fn ($field) => array_key_exists($field, $validated))
            ->map(fn ($field) => (bool) $validated[$field])
            ->all();

        if (array_key_exists('members_manage_labels', $validated)) {
            $settings[Features::MEMBERS_MANAGE_LABELS] = (bool) $validated['members_manage_labels'];
        }

        if (isset($validated['features'])) {
            // Only the known areas, merged over what is stored: a stale key from an
            // old form cannot invent a setting.
            $settings['features'] = [
                ...($workspace->settings['features'] ?? []),
                ...array_map('boolval', array_intersect_key(
                    $validated['features'],
                    Features::OPTIONAL,
                )),
            ];
        }

        if ($settings !== []) {
            $workspace->forceFill(['settings' => [...($workspace->settings ?? []), ...$settings]])->save();
        }

        // Trusting typed addresses applies to what is already here as well as to what
        // arrives next: the widget issues accepted earlier are linked now, by the same
        // rule, rather than staying invisible to the clients who reported them.
        if (ReporterLink::trustsUnverified($workspace)) {
            $linked = ReporterLink::backfill($workspace)['linked'];

            if ($linked > 0) {
                return back()->with('success', $linked === 1
                    ? 'Workspace updated. 1 existing widget issue was linked to the client who reported it.'
                    : "Workspace updated. {$linked} existing widget issues were linked to the clients who reported them.");
            }
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

        // Stop the billing first, and refuse to delete if Stripe will not confirm it:
        // a workspace nobody can reach any more that still renews every month is the
        // worst outcome available here, and a failed delete can simply be tried again.
        if (! $this->cancelSubscriptions($workspace)) {
            return back()->withErrors([
                'confirm' => 'The subscription could not be cancelled, so nothing was deleted. '
                    .'Please try again in a moment.',
            ]);
        }

        $workspace->delete();

        $request->session()->flash('success', 'Workspace deleted.');

        return redirect_across_domains(central_url('/'));
    }

    /**
     * End every subscription that would otherwise renew, immediately rather than at
     * the period's end: there is nothing left to use for the rest of the month.
     *
     * One already set to end at the period's end is left alone — it will not charge
     * again. One Stripe no longer has is marked cancelled here to match.
     */
    private function cancelSubscriptions(Workspace $workspace): bool
    {
        $live = $workspace->subscriptions()
            ->notCanceled()
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->get();

        foreach ($live as $subscription) {
            try {
                $subscription->cancelNow();
            } catch (InvalidRequestException $e) {
                if ($e->getStripeCode() !== 'resource_missing') {
                    report($e);

                    return false;
                }

                $subscription->markAsCanceled();
            } catch (ApiErrorException $e) {
                report($e);

                return false;
            }
        }

        return true;
    }
}
