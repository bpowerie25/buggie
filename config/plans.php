<?php

/*
|--------------------------------------------------------------------------
| Plans
|--------------------------------------------------------------------------
|
| Limits are placeholders chosen to be plausible, not decided. Change the numbers
| here and nothing else needs touching — enforcement reads this file.
|
| The metered quantity is reports per calendar month, because that is the thing that
| actually scales with usage: every report costs storage, a screenshot and a worker.
| Projects and members are counted because they are what customers compare on.
|
| `price_id` is a Stripe price, so a plan with none cannot be subscribed to.
|
*/

return [

    'default' => 'free',

    // What a workspace gets during its trial, before anyone has paid anything.
    'trial' => 'studio',

    // Warn in-app once usage passes this fraction of the monthly allowance.
    'warn_at' => 0.8,

    /*
     * How many reports of the same bug are metered before the rest are free.
     *
     * Duplicates collapse into one issue, which is the point of the product, so
     * billing forty reports for one bug contradicted the pitch. Past this many in a
     * month, a report sharing a fingerprint keeps its occurrence but drops its
     * payload — nobody needs the sixth screenshot of the same broken button — and a
     * report that costs nothing to store should cost nothing to send.
     *
     * It also means one viral bug cannot exhaust the month's allowance and switch
     * reporting off for everybody.
     */
    'collapse_after' => (int) env('BUGGIE_COLLAPSE_AFTER', 5),

    'plans' => [

        /*
         * What every self-hosted install runs on. Not offered for sale and never
         * selected in hosted mode — it exists so that the same limit checks can run
         * in both modes without being littered with conditionals.
         */
        'self_hosted' => [
            'name' => 'Self-hosted',
            'price_id' => null,
            'price' => 'Free',
            'interval' => 'month',
            'blurb' => 'Your server, your rules.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => null,
            ],
        ],

        'free' => [
            'name' => 'Free',
            'price_id' => null,
            'price' => '£0',
            'interval' => 'month',
            'blurb' => 'Enough to run one project properly.',
            'limits' => [
                'projects' => 3,
                'members' => 3,
                'reports_per_month' => 100,
            ],
        ],

        /*
         * Paid plans meter reports and nothing else.
         *
         * Projects and people cost nothing to host; screenshots and payloads do. An
         * agency with twenty quiet client sites is not a more expensive customer than
         * one with three busy ones, and capping projects punishes exactly the person
         * this was built for. Charging per seat is worse still: clients are seats, and
         * inviting clients is the entire point of the product.
         */
        'studio' => [
            'name' => 'Studio',
            'price_id' => env('STRIPE_PRICE_STUDIO'),
            'price' => '£19',
            'interval' => 'month',
            'blurb' => 'For a freelancer or small studio. Every client, every project.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => 2_000,
            ],
        ],

        'agency' => [
            'name' => 'Agency',
            'price_id' => env('STRIPE_PRICE_AGENCY'),
            'price' => '£49',
            'interval' => 'month',
            'blurb' => 'For a busy agency, or anyone whose reports outgrew Studio.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => 20_000,
            ],
        ],

    ],

];
