<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Billing\Currency;
use App\Support\Billing\Interval;
use App\Support\Billing\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Central-domain root. Signed-out visitors get the marketing page; signed-in users
 * are dropped back into their last workspace, or the picker if they have none.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response|SymfonyResponse
    {
        $user = $request->user();

        if ($user === null) {
            $currency = Currency::resolve($request);
            $interval = Interval::resolve($request);

            return Inertia::render('welcome', [
                // Read from config rather than written into the page, so the prices
                // a visitor is shown can never drift from the limits actually
                // enforced. self_hosted is included deliberately: it is the honest
                // comparison, and hiding it would be the wrong kind of selling.
                'plans' => array_map(
                    fn (Plan $plan) => $plan->toArray($currency, $interval),
                    Plan::all(),
                ),
                'currency' => $currency,
                'currencies' => array_map(
                    fn (string $code) => [
                        'code' => $code,
                        'symbol' => Currency::symbol($code),
                    ],
                    array_keys(Currency::all()),
                ),
                'interval' => $interval,
                'intervals' => array_map(
                    fn (string $key) => [
                        'key' => $key,
                        'label' => Interval::label($key),
                    ],
                    array_keys(Interval::all()),
                ),
                'pricesExcludeTax' => (bool) config('plans.prices_exclude_tax'),
                'hosted' => (bool) config('buggie.hosted'),
                'repository' => 'https://github.com/bpowerie25/buggie',
            ]);
        }

        $workspace = $user->last_workspace_id
            ? Workspace::find($user->last_workspace_id)
            : $user->workspaces()->orderBy('name')->first();

        if ($workspace && $user->belongsToWorkspace($workspace)) {
            return redirect_across_domains(workspace_url($workspace->slug));
        }

        return $user->workspaces()->exists()
            ? redirect()->route('workspaces.index')
            : redirect()->route('workspaces.create');
    }
}
