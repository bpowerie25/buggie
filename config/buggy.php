<?php

$domain = env('APP_DOMAIN', 'buggy.localhost:8080');

return [

    /*
    |--------------------------------------------------------------------------
    | Central domain
    |--------------------------------------------------------------------------
    |
    | The bare domain serving marketing, auth and the workspace picker. Workspaces
    | live on subdomains of it: acme.buggy.app.
    |
    | 'domain' may carry a port for local development. 'host' is the same value with
    | the port stripped, because Route::domain() matches against Request::getHost(),
    | which never includes one. Laravel's URL generator adds the current request's
    | port back on when generating, so route() still produces working dev URLs.
    |
    */

    'domain' => $domain,

    'host' => explode(':', $domain, 2)[0],

];
