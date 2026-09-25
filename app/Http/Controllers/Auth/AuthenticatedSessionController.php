<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\SendsUsersOnwards;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Registration\Registration;
use App\Support\TwoFactor\PendingLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    use SendsUsersOnwards;

    public function create(Request $request, Registration $registration): Response
    {
        return Inertia::render('auth/login', [
            'status' => session('status'),
            // A link to a page that refuses is worse than no link.
            'canRegister' => $registration->admits($request) !== null,
            'requestAccessUrl' => $this->requestAccessUrl($request, $registration),
        ]);
    }

    /**
     * Where "Request access" goes: the workspace they came from, when they came from
     * one that exists, and otherwise the operators.
     */
    private function requestAccessUrl(Request $request, Registration $registration): ?string
    {
        if (! $registration->mode()->acceptsAccessRequests()) {
            return null;
        }

        $slug = $request->query('workspace');

        return is_string($slug) && Workspace::where('slug', $slug)->exists()
            ? workspace_url($slug, 'request-access')
            : central_url('request-access');
    }

    public function store(Request $request): SymfonyResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Checked without signing anybody in. An account with a second factor must
        // not hold a real session while the second factor is still outstanding, and
        // attempt() would give it one — logging back out again would cycle the
        // remember token and knock every other device off with it.
        /*
         * Throttled twice: five wrong passwords a minute for one address from one
         * place, and twenty a minute from one IP whatever the address, so guessing
         * one password and spraying one password across many accounts both stall.
         * Counted only on failure; a correct password clears the per-address count.
         */
        $account = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        $address = 'login-ip:'.$request->ip();

        foreach ([$account => 5, $address => 20] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages([
                    'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($account, 60);
            RateLimiter::hit($address, 60);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($account);

        $user = Auth::getLastAttempted();

        if ($user->hasTwoFactorEnabled()) {
            PendingLogin::begin($request, $user, $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return $this->onwards($request);
    }

    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect_across_domains(central_url('/'));
    }
}
