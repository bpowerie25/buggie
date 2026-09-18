<?php

$domain = env('APP_DOMAIN', 'buggy.localhost:8080');

return [

    /*
    |--------------------------------------------------------------------------
    | Hosted mode
    |--------------------------------------------------------------------------
    |
    | Buggy is AGPL-3.0 and the same code runs both ways. `hosted` is true only for
    | the commercial service at buggy.app, where plans, limits and billing apply.
    |
    | Self-hosted installs get everything, with no limits and no billing — you are
    | running it on your own hardware and there is nothing to meter. Nothing here
    | phones home in either mode.
    |
    */

    'hosted' => (bool) env('BUGGY_HOSTED', false),

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

    /*
    |--------------------------------------------------------------------------
    | Operators
    |--------------------------------------------------------------------------
    |
    | Addresses allowed into the queue dashboard. This is infrastructure, not a
    | workspace feature: it shows jobs from every tenant, so workspace roles are the
    | wrong thing to check. Empty means nobody, which is the right default.
    |
    */

    'operators' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BUGGY_OPERATORS', '')),
    ))),

];
