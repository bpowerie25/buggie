<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\Workspace;
use App\Support\Invitations\PendingInvitation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where somebody lands once they are actually signed in.
 *
 * Shared because there are now two doors into the same hallway: a password alone,
 * and a password followed by a second factor. Both have to make the same choice
 * about invitations, `intended` and which workspace to open.
 */
trait SendsUsersOnwards
{
    protected function onwards(Request $request): SymfonyResponse
    {
        // An invitation outranks `intended`: the invitation is what they were doing,
        // and `intended` is usually just wherever the guest middleware bounced them.
        if ($invitation = PendingInvitation::destinationFor($request)) {
            $request->session()->forget('url.intended');

            return redirect_across_domains($invitation);
        }

        // intended() may hold a URL on any workspace subdomain.
        return redirect_across_domains(
            $request->session()->pull('url.intended', $this->destinationFor($request)),
        );
    }

    /** Drop the user back into their last workspace, or the picker if they have none. */
    protected function destinationFor(Request $request): string
    {
        $user = $request->user();

        $workspace = $user->last_workspace_id
            ? Workspace::find($user->last_workspace_id)
            : $user->workspaces()->orderBy('name')->first();

        if ($workspace && $user->belongsToWorkspace($workspace)) {
            return workspace_url($workspace->slug);
        }

        return route('workspaces.index');
    }
}
