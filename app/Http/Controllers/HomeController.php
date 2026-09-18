<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
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
            return Inertia::render('welcome', [
                // Read from config rather than written into the page, so the prices
                // a visitor is shown can never drift from the limits actually
                // enforced. self_hosted is included deliberately: it is the honest
                // comparison, and hiding it would be the wrong kind of selling.
                'plans' => array_map(
                    fn (\App\Support\Billing\Plan $plan) => $plan->toArray(),
                    \App\Support\Billing\Plan::all(),
                ),
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
