<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Only the widget ingest endpoints are cross-origin: they are called from the
    | customer's own application, on their own domain. Per-key origin checking happens
    | in IngestController — this only opens the browser's door far enough for that
    | check to run.
    |
    | The application itself is same-origin and is deliberately not listed here.
    |
    */

    'paths' => ['api/ingest/*', 'w/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    // The endpoint validates Origin against the key's allowlist and replies 403 when
    // it does not match, which is a more useful answer than a silent CORS failure.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    // No cookies: the widget must never carry the reporter's session anywhere.
    'supports_credentials' => false,

];
