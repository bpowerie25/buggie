<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\SendsUsersOnwards;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Registration\Registration;
use App\Support\TwoFactor\PendingLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

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
