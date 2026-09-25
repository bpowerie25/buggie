<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession as Base;

/**
 * Laravel's AuthenticateSession, for session sign-ins only: it ends any session
 * whose remembered password hash no longer matches, so a password reset signs out
 * every other browser.
 *
 * It assumes the default guard is the session guard and calls methods only that
 * guard has. A request authenticated some other way — an API token through
 * Sanctum — has no session sign-in to end, and would otherwise be a 500.
 */
class AuthenticateSession extends Base
{
    public function handle($request, Closure $next)
    {
        // The default guard itself: guard() in the parent returns the auth manager,
        // which proxies to it and is never a SessionGuard.
        if (! $this->auth->guard() instanceof SessionGuard) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
