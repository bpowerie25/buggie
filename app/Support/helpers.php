<?php

use App\Models\Workspace;

if (! function_exists('workspace_url')) {
    /**
     * Absolute URL for a workspace subdomain, preserving the central domain's
     * scheme and port so it works in local development too.
     */
    function workspace_url(Workspace|string $workspace, string $path = '/'): string
    {
        $slug = $workspace instanceof Workspace ? $workspace->slug : $workspace;
        $scheme = str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';

        return $scheme.'://'.$slug.'.'.config('buggy.domain').'/'.ltrim($path, '/');
    }
}

if (! function_exists('redirect_across_domains')) {
    /**
     * Redirect to another origin, in a way an Inertia request can follow.
     *
     * Workspaces live on subdomains, so signing in, signing out, switching workspace
     * and accepting an invitation all cross an origin boundary. An ordinary 302 is
     * fine for a full page load, but Inertia issues these as XHR: the browser follows
     * the redirect to the other origin, the cross-origin request is refused, and the
     * user sees nothing happen at all.
     *
     * Inertia::location returns a 409 with X-Inertia-Location, which tells the client
     * to perform a hard visit instead.
     */
    function redirect_across_domains(string $url): \Symfony\Component\HttpFoundation\Response
    {
        if (request()->header('X-Inertia')) {
            return \Inertia\Inertia::location($url);
        }

        return redirect($url);
    }
}

if (! function_exists('central_url')) {
    function central_url(string $path = '/'): string
    {
        $scheme = str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';

        return $scheme.'://'.config('buggy.domain').'/'.ltrim($path, '/');
    }
}
