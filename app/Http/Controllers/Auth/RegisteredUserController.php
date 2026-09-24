<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Http\Middleware\EnsureRegistrationIsOpen;
use App\Support\Invitations\PendingInvitation;
use App\Support\Registration\Admission;
use App\Support\Registration\Registration;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RegisteredUserController extends Controller
{
    public function create(Registration $registration): Response
    {
        return Inertia::render('auth/register', [
            // Said, because it is not an ordinary account: on a self-hosted install
            // this one will run the server.
            'firstRun' => $registration->isFirstRun() && ! config('buggie.hosted'),
        ]);
    }

    public function store(Request $request, Registration $registration): SymfonyResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request, $registration, $validated) {
            // The middleware has already let them through, but for the first run that
            // was a check, not a claim. Two strangers on an empty install both pass the
            // check; only one wins the claim, and the other is judged as if the first
            // run had never been on offer.
            $admission = $registration->admits($request);

            if ($admission === Admission::FirstRun && ! $registration->claimFirstRun()) {
                $admission = $registration->admitsOtherwise($request);
            }

            if ($admission === null) {
                throw new HttpResponseException(EnsureRegistrationIsOpen::refusal($request));
            }

            $user = User::create($validated);

            // Whoever sets up a self-hosted install runs it. Never on the hosted
            // service, where the first sign-up is a customer like any other.
            if ($admission === Admission::FirstRun && ! config('buggie.hosted')) {
                $user->forceFill(['is_operator' => true])->save();
            }

            return $user;
        });

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
