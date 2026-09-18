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

if (! function_exists('central_url')) {
    function central_url(string $path = '/'): string
    {
        $scheme = str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';

        return $scheme.'://'.config('buggy.domain').'/'.ltrim($path, '/');
    }
}
