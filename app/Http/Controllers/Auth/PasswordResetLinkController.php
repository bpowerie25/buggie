<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $bucket = 'password-reset:'.sha1(strtolower($validated['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($bucket, 5)) {
            return back()->withErrors([
                'email' => 'Too many attempts. Try again in a few minutes.',
            ]);
        }

        RateLimiter::hit($bucket, 900);

        Password::sendResetLink($validated);

        // Always the same answer, whether or not the address is registered. Saying
        // "no account with that email" turns this form into a way of finding out who
        // has an account here.
        return back()->with(
            'status',
            'If that address has an account, a reset link is on its way.',
        );
    }
}
