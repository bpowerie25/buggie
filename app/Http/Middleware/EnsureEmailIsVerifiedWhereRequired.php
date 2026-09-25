<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends somebody with an unconfirmed address to confirm it, when this install asks
 * for that (buggie.require_verified_email: on by default for the hosted service,
 * off for self-hosted, where nobody signs up without an invitation or the first run).
 */
class EnsureEmailIsVerifiedWhereRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && config('buggie.require_verified_email') && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Confirm your email address first.'], 409)
                : redirect_across_domains(central_url('email/verify'));
        }

        return $next($request);
    }
}
