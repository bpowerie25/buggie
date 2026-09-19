<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token works in the workspace it was issued for, and nowhere else.
 *
 * Without this, a token would be as good as its owner's session: one key pasted into
 * a throwaway script would reach every client workspace that person had ever been
 * invited to. The subdomain says which workspace is being asked for; the token says
 * which one it is allowed to ask about. They have to agree.
 */
class EnsureTokenMatchesWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Not a token request at all — a session, say. Nothing to check.
        if ($token === null) {
            return $next($request);
        }

        $workspace = app(Tenancy::class)->currentOrFail();

        // 404, not 403: a token for another workspace should not be able to find out
        // that this one exists.
        abort_unless($token->workspace_id === $workspace->id, 404);

        abort_unless($request->user()->belongsToWorkspace($workspace), 404);

        return $next($request);
    }
}
