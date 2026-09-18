<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace from the request subdomain and binds it for the rest of
 * the request.
 *
 * On the central domain no workspace is bound, and strict mode stays on — so any
 * tenant-model query that slips into a central-domain route throws loudly instead
 * of quietly returning every customer's rows.
 */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);
        $tenancy->strict();

        if ($slug = $this->subdomain($request)) {
            $workspace = Workspace::where('slug', $slug)->first();

            abort_if($workspace === null, 404, 'Workspace not found.');

            $tenancy->set($workspace);

            // So route('projects.index') works without passing the subdomain every time.
            URL::defaults(['workspace' => $workspace->slug]);

            // The domain parameter has served its purpose. Leaving it in place would
            // shift every controller's arguments along by one, because the dispatcher
            // splices resolved bindings in positionally.
            $request->route()?->forgetParameter('workspace');
        }

        return $next($request);
    }

    /** The subdomain portion, or null on the central domain. */
    protected function subdomain(Request $request): ?string
    {
        $central = $this->centralHost();
        $host = strtolower($request->getHost());

        if ($host === $central) {
            return null;
        }

        if (! str_ends_with($host, '.'.$central)) {
            return null;
        }

        $slug = substr($host, 0, -(strlen($central) + 1));

        // Only a single label is a workspace. Anything deeper is not ours.
        return str_contains($slug, '.') ? null : $slug;
    }

    protected function centralHost(): string
    {
        return strtolower((string) config('buggy.host'));
    }
}
