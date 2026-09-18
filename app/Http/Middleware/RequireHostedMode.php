<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the parts that exist only on the commercial hosted service.
 *
 * A self-hosted install has no plans to choose between and nobody to pay, so these
 * routes answer 404 — the same as any other address that is not part of this
 * application.
 *
 * Deliberately a middleware rather than a condition around the route definitions:
 * route registration happens during bootstrap, which makes it invisible to anything
 * that changes configuration afterwards, and route caching would bake in whichever
 * mode was active when the cache was built.
 */
class RequireHostedMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('buggie.hosted'), 404);

        return $next($request);
    }
}
