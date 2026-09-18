<?php

namespace App\Http\Controllers;

use App\Actions\InviteToWorkspace;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Accepting an invitation. Lives on the workspace domain but outside the membership
 * gate, because the whole point is that the visitor is not a member yet.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response|SymfonyResponse
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->with('workspace', 'invitedBy')
            ->where('token', $token)
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('invitations/invalid');
        }

        if ($request->user() === null) {
            // Come back here once they have an account or a session.
            $request->session()->put('invitation_token', $token);

            return redirect_across_domains(central_url('register').'?invitation='.$token);
        }

        return Inertia::render('invitations/show', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'workspace' => $invitation->workspace->name,
                'invited_by' => $invitation->invitedBy?->name,
            ],
        ]);
    }

    public function accept(Request $request, string $token, InviteToWorkspace $action): SymfonyResponse
    {
        $invitation = Invitation::withoutGlobalScopes()->where('token', $token)->firstOrFail();

        abort_unless($invitation->isPending(), 410, 'This invitation is no longer valid.');
        abort_if($request->user() === null, 401);

        $action->accept($invitation, $request->user());

        $request->session()->forget('invitation_token');

        // Flashed rather than chained: Inertia::location returns a plain response,
        // which has no ->with().
        $request->session()->flash('success', "Welcome to {$invitation->workspace->name}.");

        return redirect_across_domains(workspace_url($invitation->workspace->slug));
    }
}
