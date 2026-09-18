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
    'trial' => 'team',

    // Warn in-app once usage passes this fraction of the monthly allowance.
    'warn_at' => 0.8,

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
            'blurb' => 'For one small project.',
            'limits' => [
                'projects' => 3,
                'members' => 3,
                'reports_per_month' => 100,
            ],
        ],

        'team' => [
            'name' => 'Team',
            'price_id' => env('STRIPE_PRICE_TEAM'),
            'price' => '£29',
            'interval' => 'month',
            'blurb' => 'For an agency running a handful of client projects.',
            'limits' => [
                'projects' => 15,
                'members' => 15,
                'reports_per_month' => 2_000,
            ],
        ],

        'business' => [
            'name' => 'Business',
            'price_id' => env('STRIPE_PRICE_BUSINESS'),
            'price' => '£99',
            'interval' => 'month',
            'blurb' => 'Unlimited projects and people.',
            'limits' => [
                'projects' => null,      // null means no limit
                'members' => null,
                'reports_per_month' => 20_000,
            ],
        ],

    ],

];
