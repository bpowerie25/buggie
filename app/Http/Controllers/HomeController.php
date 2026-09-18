<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central-domain root. Signed-out visitors get the marketing page; signed-in users
 * are dropped back into their last workspace, or the picker if they have none.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return Inertia::render('welcome');
        }

        $workspace = $user->last_workspace_id
            ? Workspace::find($user->last_workspace_id)
            : $user->workspaces()->orderBy('name')->first();

        if ($workspace && $user->belongsToWorkspace($workspace)) {
            return redirect(workspace_url($workspace->slug));
        }

        return $user->workspaces()->exists()
            ? redirect()->route('workspaces.index')
            : redirect()->route('workspaces.create');
    }
}
