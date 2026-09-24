<?php

namespace App\Http\Middleware;

use App\Support\Registration\Registration;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards both halves of /register: the form and the submission.
 *
 * Refuses with a page that says why, not a 404. A person who followed a link here is
 * owed an explanation and a next step; "not found" on a page they can see linked from
 * the sign-in screen of an older install reads as the server being broken.
 */
class EnsureRegistrationIsOpen
{
    public function __construct(private Registration $registration) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->registration->admits($request) !== null) {
            return $next($request);
        }

        return self::refusal($request);
    }

    public static function refusal(Request $request): Response
    {
        $mode = app(Registration::class)->mode();

        return Inertia::render('auth/registration-closed', [
            'requestAccessUrl' => $mode->acceptsAccessRequests() ? central_url('request-access') : null,
        ])->toResponse($request)->setStatusCode(403);
    }
}
