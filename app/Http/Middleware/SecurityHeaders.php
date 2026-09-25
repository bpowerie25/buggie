<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every page gets.
 *
 * - HSTS, over HTTPS only: a browser that has been here once never tries plain HTTP
 *   again, so a network in between cannot quietly downgrade a sign-in. The hosted
 *   service covers its subdomains too, since every workspace is one and all are
 *   HTTPS; a self-hosted install decides that for itself.
 * - A Content-Security-Policy limited to what cannot break the app: no framing by
 *   other sites, no <base> redirecting relative URLs, no plugins. A script policy
 *   belongs here too, but has to be proven in a browser against Vite and Inertia
 *   first, so it is not guessed at.
 * - nosniff and a referrer policy that sends only the origin elsewhere, so an
 *   issue URL is not handed to every site a link in it points at.
 *
 * A response that already set a policy of its own — an attachment, a brand logo —
 * keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000'.(config('buggie.hosted') ? '; includeSubDomains' : ''));
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }

        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
