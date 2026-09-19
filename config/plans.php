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
     * Currencies a visitor can be quoted in.
     *
     * Euro is the default: the company is Irish, so it is the currency the books are
     * kept in and the one that avoids conversion on most sales. Sterling and dollars
     * are real local prices rather than a conversion of the euro one — a price ending
     * in 47 looks like arithmetic rather than a decision.
     */
    'default_currency' => env('BUGGIE_DEFAULT_CURRENCY', 'EUR'),

    'currencies' => [
        'EUR' => ['symbol' => '€', 'label' => 'EUR'],
        'GBP' => ['symbol' => '£', 'label' => 'GBP'],
        'USD' => ['symbol' => '$', 'label' => 'USD'],
    ],

    /*
     * How often a subscription is charged.
     *
     * Monthly is the default because it is the smaller commitment and the one an
     * agency will try first. Annual exists because these customers have lumpy
     * workloads: the ones who drift away in month four are the same ones who would
     * not have, had they bought a year. `months` is what a year is billed as, and is
     * what the saving is worked out from — so pricing a year at ten months' money
     * means changing one number, not editing six prices and a marketing line.
     */
    'default_interval' => env('BUGGIE_DEFAULT_INTERVAL', 'month'),

    'intervals' => [
        'month' => ['label' => 'Monthly', 'suffix' => '/ month', 'months' => 1],
        'year' => ['label' => 'Annual', 'suffix' => '/ year', 'months' => 12],
    ],

    /*
     * Every price here is quoted EXCLUDING tax.
     *
     * These are sold to businesses, which expect ex-VAT pricing, and the tax due
     * depends entirely on where the customer is and whether they have a VAT number.
     * Stripe Tax works it out at checkout: Irish VAT domestically, the customer's own
     * rate for EU consumers, nothing for EU businesses that supply a valid VAT number
     * under the reverse charge, and UK or US rules for those.
     */
    'prices_exclude_tax' => true,

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
            'prices' => null,
            'price' => 'Free',
            'blurb' => 'Your server, your rules.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => null,
            ],
        ],

        'free' => [
            'name' => 'Free',
            'prices' => null,
            'price' => '0',
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
         *
         * Amounts are numbers, not strings, because the annual saving is worked out
         * from them. A display string alongside them would be a second copy of the
         * price, free to drift from the one that is charged.
         */
        'studio' => [
            'name' => 'Studio',
            'prices' => [
                'EUR' => [
                    'month' => ['amount' => 19, 'price_id' => env('STRIPE_PRICE_STUDIO_EUR')],
                    'year' => ['amount' => 190, 'price_id' => env('STRIPE_PRICE_STUDIO_EUR_YEAR')],
                ],
                'GBP' => [
                    'month' => ['amount' => 16, 'price_id' => env('STRIPE_PRICE_STUDIO_GBP')],
                    'year' => ['amount' => 160, 'price_id' => env('STRIPE_PRICE_STUDIO_GBP_YEAR')],
                ],
                'USD' => [
                    'month' => ['amount' => 19, 'price_id' => env('STRIPE_PRICE_STUDIO_USD')],
                    'year' => ['amount' => 190, 'price_id' => env('STRIPE_PRICE_STUDIO_USD_YEAR')],
                ],
            ],
            'blurb' => 'For a freelancer or small studio. Every client, every project.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => 2_000,
            ],
        ],

        'agency' => [
            'name' => 'Agency',
            'prices' => [
                'EUR' => [
                    'month' => ['amount' => 49, 'price_id' => env('STRIPE_PRICE_AGENCY_EUR')],
                    'year' => ['amount' => 490, 'price_id' => env('STRIPE_PRICE_AGENCY_EUR_YEAR')],
                ],
                'GBP' => [
                    'month' => ['amount' => 42, 'price_id' => env('STRIPE_PRICE_AGENCY_GBP')],
                    'year' => ['amount' => 420, 'price_id' => env('STRIPE_PRICE_AGENCY_GBP_YEAR')],
                ],
                'USD' => [
                    'month' => ['amount' => 49, 'price_id' => env('STRIPE_PRICE_AGENCY_USD')],
                    'year' => ['amount' => 490, 'price_id' => env('STRIPE_PRICE_AGENCY_USD_YEAR')],
                ],
            ],
            'blurb' => 'For a busy agency, or anyone whose reports outgrew Studio.',
            'limits' => [
                'projects' => null,
                'members' => null,
                'reports_per_month' => 20_000,
            ],
        ],

    ],

];
