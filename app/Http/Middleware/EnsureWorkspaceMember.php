<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for workspace-domain routes: there must be a workspace, and the signed-in
 * user must be a member of it.
 *
 * Non-members get a 404 rather than a 403 — a 403 confirms the workspace exists,
 * which is a free enumeration oracle for customer names.
 */
class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);

        abort_unless($tenancy->check(), 404);

        $user = $request->user();

        abort_if($user === null, 401);
        abort_unless($user->belongsToWorkspace($tenancy->currentOrFail()), 404);

        if ($user->last_workspace_id !== $tenancy->id()) {
            $user->forceFill(['last_workspace_id' => $tenancy->id()])->saveQuietly();
        }

        return $next($request);
    }
}
