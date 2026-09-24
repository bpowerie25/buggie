<?php

namespace App\Http\Controllers;

use App\Actions\RequestAccess;
use App\Support\Registration\Registration;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "let me in" form, on an install where sign-up is by invitation.
 *
 * On a workspace's own domain it asks that workspace. On the central domain there is
 * no workspace to ask, so it asks which organisation they are from and goes to the
 * operators as a request for a new one.
 *
 * Every submission that gets past validation and the rate limits is answered the same
 * way — "your request has been received" — whether it was kept or quietly dropped.
 */
class AccessRequestController extends Controller
{
    /** Per address: a person does not need to ask more often than this. */
    private const PER_EMAIL = 3;

    private const PER_EMAIL_DECAY = 86400;

    /** Per IP: generous enough for an office behind one address. */
    private const PER_IP = 10;

    private const PER_IP_DECAY = 3600;

    public function __construct(private Registration $registration, private Tenancy $tenancy) {}

    public function create(Request $request): Response
    {
        $this->ensureAccepting();

        $workspace = $this->tenancy->current();

        return Inertia::render('auth/request-access', [
            // The host they are looking at, which tells them nothing they did not type.
            'workspaceHost' => $workspace ? parse_url(workspace_url($workspace->slug), PHP_URL_HOST) : null,
            'received' => (bool) $request->session()->get('access_requested'),
            'loginUrl' => central_url($workspace ? 'login?workspace='.$workspace->slug : 'login'),
        ]);
    }

    public function store(Request $request, RequestAccess $action): RedirectResponse
    {
        $this->ensureAccepting();

        $workspace = $this->tenancy->current();
        $ipKey = 'access-request:ip:'.$request->ip();

        // A field no person can see. Whatever fills it in is told it succeeded, and
        // still counts against the address it came from.
        if (filled($request->input('website'))) {
            RateLimiter::hit($ipKey, self::PER_IP_DECAY);

            return $this->received();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'organisation' => [$workspace ? 'nullable' : 'required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $emailKey = 'access-request:email:'.sha1(strtolower(trim($validated['email'])));

        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)
            || RateLimiter::tooManyAttempts($emailKey, self::PER_EMAIL)) {
            return back()->withErrors([
                'email' => 'Too many requests. Please try again later.',
            ]);
        }

        RateLimiter::hit($ipKey, self::PER_IP_DECAY);
        RateLimiter::hit($emailKey, self::PER_EMAIL_DECAY);

        $action->handle($workspace, $validated);

        return $this->received();
    }

    private function received(): RedirectResponse
    {
        return back()->with('access_requested', true);
    }

    /** Not a feature this install offers unless the operator has turned it on. */
    private function ensureAccepting(): void
    {
        abort_unless($this->registration->mode()->acceptsAccessRequests(), 404);
    }
}
