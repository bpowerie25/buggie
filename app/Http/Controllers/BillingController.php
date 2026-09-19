<?php

namespace App\Http\Controllers;

use App\Support\Billing\Plan;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(): Response
    {
        $this->authorize('manageBilling', $this->tenancy->currentOrFail());

        $workspace = $this->tenancy->currentOrFail();
        $subscription = $workspace->subscription();

        return Inertia::render('settings/billing', [
            'plan' => $workspace->plan()->toArray(),
            'usage' => $workspace->usage(),
            'plans' => array_map(
                fn (Plan $plan) => $plan->toArray(\App\Support\Billing\Currency::resolve($request)),
                Plan::all(),
            ),
            'subscription' => $subscription ? [
                'status' => $subscription->stripe_status,
                'on_grace_period' => $subscription->onGracePeriod(),
                'ends_at' => $subscription->ends_at?->toDateString(),
                'renews_at' => $subscription->asStripeSubscription()?->current_period_end
                    ? date('Y-m-d', $subscription->asStripeSubscription()->current_period_end)
                    : null,
            ] : null,
            'trial_ends_at' => $workspace->trial_ends_at?->toDateString(),
            'on_trial' => (bool) $workspace->trial_ends_at?->isFuture(),
            'card' => $workspace->pm_last_four ? [
                'brand' => $workspace->pm_type,
                'last_four' => $workspace->pm_last_four,
            ] : null,
            // Stripe is optional in development; the screen says so rather than
            // producing a checkout button that cannot work.
            'configured' => (bool) config('cashier.secret'),
        ]);
    }

    /** Hand off to Stripe Checkout rather than handling card details ourselves. */
    public function checkout(Request $request): SymfonyResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('manageBilling', $workspace);

        $validated = $request->validate([
            'plan' => ['required', Rule::in(array_keys((array) config('plans.plans')))],
        ]);

        $plan = Plan::find($validated['plan']);

        abort_unless($plan->isSubscribable(), 422, 'That plan cannot be subscribed to.');

        // The currency they were quoted in, carried through to what they are charged.
        // Being shown $19 and billed €19 is the kind of surprise that generates a
        // chargeback rather than an email.
        $currency = \App\Support\Billing\Currency::resolve($request);

        return $workspace
            ->newSubscription('default', $plan->priceId($currency))
            ->checkout([
                'success_url' => workspace_url($workspace->slug, 'settings/billing?checkout=done'),
                'cancel_url' => workspace_url($workspace->slug, 'settings/billing'),

                // Prices are quoted excluding tax, so Stripe Tax adds it: Irish VAT
                // domestically, the customer's own rate for EU consumers, UK or US
                // rules for those.
                'automatic_tax' => ['enabled' => true],

                // And collect a VAT number, which is what lets an EU business be
                // zero-rated under the reverse charge. Without this every EU company
                // pays Irish VAT they should not be paying and has to claim it back.
                'tax_id_collection' => ['enabled' => true],
                'customer_update' => ['name' => 'auto', 'address' => 'auto'],
            ]);
    }

    /** Stripe's own portal, so card changes and invoices are never our problem. */
    public function portal(Request $request): SymfonyResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('manageBilling', $workspace);

        abort_unless($workspace->hasStripeId(), 404, 'This workspace has no billing account yet.');

        return $workspace->redirectToBillingPortal(
            workspace_url($workspace->slug, 'settings/billing'),
        );
    }

    public function cancel(): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('manageBilling', $workspace);

        $subscription = $workspace->subscription();

        abort_if($subscription === null, 404);

        // At period end, not immediately: they paid for the rest of the month.
        $subscription->cancel();

        return back()->with('success', 'Your plan will end at the close of this billing period.');
    }

    public function resume(): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        $this->authorize('manageBilling', $workspace);

        $subscription = $workspace->subscription();

        abort_if($subscription === null || ! $subscription->onGracePeriod(), 404);

        $subscription->resume();

        return back()->with('success', 'Welcome back — your plan has been resumed.');
    }
}
