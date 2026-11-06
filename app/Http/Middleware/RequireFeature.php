<?php

namespace App\Http\Middleware;

use App\Support\Settings\Features;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:timeline` — 404 when the workspace's admin has switched that area off.
 *
 * A middleware on the route, for the reason RequireHostedMode is: the answer depends
 * on the workspace being served, which is only known per request.
 */
class RequireFeature
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::enabled($this->tenancy->current(), $feature), 404);

        return $next($request);
    }
}
