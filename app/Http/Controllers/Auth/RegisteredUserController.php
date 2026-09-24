<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Invitations\PendingInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    public function store(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($validated);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        // Someone who arrived here from an invitation is taken back to it rather
        // than to "create a workspace", which is not what they came for.
        $invitation = PendingInvitation::destinationFor($request);

        if ($invitation) {
            return redirect_across_domains($invitation);
        }

        return $user->can('create', Workspace::class)
            ? redirect()->route('workspaces.create')
            : redirect()->route('workspaces.index');
    }
}
