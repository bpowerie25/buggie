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

    /*
    |--------------------------------------------------------------------------
    | Inbound mail
    |--------------------------------------------------------------------------
    |
    | The domain Mailgun routes inbound mail for. Issues are created by writing to
    | bugs+{project token}@, and replies to notification mail come back to
    | reply+{comment token}@.
    |
    */

    'inbound_domain' => env('MAIL_INBOUND_DOMAIN', 'in.buggy.test'),

    'mailgun_signing_key' => env('MAILGUN_SIGNING_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Notification batching
    |--------------------------------------------------------------------------
    |
    | How long to gather activity on one issue for one person before sending, so a
    | burst of edits is a single email rather than one per change.
    |
    */

    'digest_delay_minutes' => (int) env('DIGEST_DELAY_MINUTES', 5),

];
