<?php

$domain = env('APP_DOMAIN', 'buggie.localhost:8080');

return [

    /*
    |--------------------------------------------------------------------------
    | Hosted mode
    |--------------------------------------------------------------------------
    |
    | Buggie is AGPL-3.0 and the same code runs both ways. `hosted` is true only for
    | the commercial service at buggie.eu, where plans, limits and billing apply.
    |
    | Self-hosted installs get everything, with no limits and no billing — you are
    | running it on your own hardware and there is nothing to meter. Nothing here
    | phones home in either mode.
    |
    */

    'hosted' => (bool) env('BUGGIE_HOSTED', false),

    /*
    |--------------------------------------------------------------------------
    | Central domain
    |--------------------------------------------------------------------------
    |
    | The bare domain serving marketing, auth and the workspace picker. Workspaces
    | live on subdomains of it: acme.buggie.eu.
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

    'inbound_domain' => env('MAIL_INBOUND_DOMAIN', 'in.buggie.test'),

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

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Bug reports collect personal data as a side effect of being useful: a
    | screenshot of whatever was on somebody's screen, the address they wrote from,
    | the account they were signed in as. Keeping that for ever is neither necessary
    | nor defensible, so it ages out.
    |
    | Issues and comments are the work product and are never pruned — they are what
    | the tracker is for. What goes is the raw intake around them.
    |
    | Days; null disables that rule. Workspaces may shorten these in their settings,
    | and may not lengthen them beyond what the operator configures here.
    |
    */

    'retention' => [
        // Delete the image. The issue keeps the description and the error.
        'screenshots' => (int) env('RETAIN_SCREENSHOT_DAYS', 180),

        // Scrub the reporter's name, address and identity from raw reports.
        'reporter_identity' => (int) env('RETAIN_REPORTER_DAYS', 180),

        // Reports marked spam or discarded were never wanted; bin them entirely.
        'dismissed_reports' => (int) env('RETAIN_DISMISSED_DAYS', 30),

        // Expired portal links, some time after they stopped working.
        'expired_portal_tokens' => (int) env('RETAIN_EXPIRED_TOKENS_DAYS', 30),

        // In-app notifications, read or not. They are a record of being told, not
        // the record of what happened — that is the issue, and it is never pruned.
        // Nobody scrolls three months back through "Ann commented", and left alone
        // the table grows for ever on the busiest workspaces.
        'notifications' => (int) env('RETAIN_NOTIFICATION_DAYS', 90),
    ],

    'operators' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BUGGIE_OPERATORS', '')),
    ))),

];
