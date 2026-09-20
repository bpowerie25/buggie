<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\SendsUsersOnwards;
use App\Http\Controllers\Controller;
use App\Support\TwoFactor\PendingLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The second half of signing in.
 *
 * The password has already been accepted and nothing has been granted for it yet.
 * Whoever is here is a guest with a name written on a slip of paper in the session,
 * and they stay a guest until a code arrives.
 */
class TwoFactorChallengeController extends Controller
{
    use SendsUsersOnwards;

    /**
     * Six digits is a million possibilities, which sounds like a lot and is not:
     * unthrottled, a script walks the whole space in under a day, and it only has to
     * find one of the three codes valid at any moment.
     */
    private const ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(Request $request): SymfonyResponse
    {
        if (! PendingLogin::user($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request): SymfonyResponse
    {
        $user = PendingLogin::user($request);

        // The session expired, or somebody arrived here directly. Nothing to say
        // about it beyond "start again".
        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        // Counted per account rather than per address. The address is the attacker's
        // to change and the account is not, and locking somebody out this way needs
        // their password first — so there is no stranger who can do it to them.
        $key = 'two-factor:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $code = (string) $request->input('code');

        // A recovery code is accepted in the same box. Somebody whose phone is in a
        // taxi should not have to find a second form to say so.
        if (! $user->acceptTwoFactorCode($code) && ! $user->consumeRecoveryCode($code)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'code' => 'That code is not right, or has already been used.',
            ]);
        }

        RateLimiter::clear($key);

        $remember = PendingLogin::remember($request);
        PendingLogin::forget($request);

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return $this->onwards($request);
    }
}
