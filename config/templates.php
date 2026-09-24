<?php

use App\Models\Status;

/*
|--------------------------------------------------------------------------
| Project templates
|--------------------------------------------------------------------------
|
| What a new project is set up with: its statuses, the workspace labels it expects
| to exist, and its custom field definitions. An agency running twenty client sites
| otherwise builds the same workflow twenty times.
|
| They live in configuration rather than in the database so that a self-hosted
| install gets them without a seeder having run, and so that changing one is a
| reviewable diff rather than an UPDATE somebody ran once on a Tuesday.
|
| `default` is what a project gets when nobody chooses anything. It is the six
| statuses every project has always been created with, read straight from
| Status::DEFAULTS rather than copied, so there is one list and not two.
|
| Three rules the loader enforces, and will refuse to create a project over:
|
| - Every status maps to a StatusCategory. Names are the customer's; categories are
|   the fixed spine `is:open`, the board and the portal all read. See workflow.md.
| - A template needs at least one open status and at least one in `done`. Without an
|   open one a new issue has nowhere to start; without a done one nothing can be
|   finished, only cancelled, and `resolved_at` is never set.
| - Exactly one status is the default, and it is an open one.
|
| Custom fields here are always internal. A field is shown to a client because
| somebody decided it should be, never because a template they picked in a hurry
| said so — "Internal estimate" and "Client reference" are both plausible template
| fields and only one of them should ever leave the building. Setting
| `visible_to_client` on a template field is rejected rather than ignored, so the
| rule is visible to whoever is editing this file.
|
| Labels are workspace-wide, not per project (see the labels table): "regression"
| should mean the same thing everywhere. A template creates the ones that are
| missing and leaves the ones that already exist exactly as they are, including
| their colour — an existing label is somebody's decision.
|
| Order is array order. A `position` key here would be a second source of truth,
| free to disagree with the order the list is read in.
|
*/

return [

    'default' => 'standard',

    'templates' => [

        /*
         * What every project got before templates existed, and what one still gets
         * when nobody chooses. No labels and no fields: a starting point that
         * assumes nothing about the work.
         */
        'standard' => [
            'name' => 'Standard',
            'blurb' => 'The six statuses every project has always started with. No labels, no fields.',
            'statuses' => Status::DEFAULTS,
            'labels' => [],
            'fields' => [],
        ],

        /*
         * A fixed-scope build for somebody else, where the thing that actually holds
         * work up is waiting on the client — for a decision, for copy, for sign-off.
         * That wait is a status rather than a label because it is where an issue sits,
         * and a board that cannot show it makes every stand-up a guessing game.
         */
        'client_website' => [
            'name' => 'Client website build',
            'blurb' => 'A build with a sign-off step: work is not done when it passes QA, it is done when the client says so.',
            'statuses' => [
                ['name' => 'Backlog',         'category' => 'backlog',   'color' => '#94a3b8', 'is_default' => false],
                ['name' => 'Scoped',          'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                ['name' => 'Building',        'category' => 'started',   'color' => '#f59e0b', 'is_default' => false],
                ['name' => 'Internal QA',     'category' => 'started',   'color' => '#8b5cf6', 'is_default' => false],
                ['name' => 'Awaiting client', 'category' => 'started',   'color' => '#0ea5e9', 'is_default' => false, 'is_awaiting_client' => true],
                ['name' => 'Live',            'category' => 'done',      'color' => '#10b981', 'is_default' => false],
                ['name' => 'Out of scope',    'category' => 'canceled',  'color' => '#6b7280', 'is_default' => false],
            ],
            'labels' => [
                ['name' => 'Content', 'color' => '#a855f7', 'description' => 'Blocked on copy, images or data somebody else owes us.'],
                ['name' => 'Design', 'color' => '#ec4899', 'description' => 'A look-and-feel decision rather than a defect.'],
                ['name' => 'Change request', 'color' => '#f97316', 'description' => 'Asked for after the scope was agreed.'],
            ],
            'fields' => [
                ['name' => 'Page URL', 'type' => 'url'],
                ['name' => 'Browser', 'type' => 'text'],
                ['name' => 'Client reference', 'type' => 'text'],
            ],
        ],

        /*
         * A retainer or maintenance agreement on something already live. Work arrives
         * rather than being planned, so the first status is a triage queue and
         * "scheduled" sits in `backlog` — noted, agreed, not this week.
         *
         * Billable is a checkbox and not a label because it is answered on every
         * issue, including with a no, and a label can only ever say yes.
         */
        'support' => [
            'name' => 'Ongoing support',
            'blurb' => 'For a live site on a retainer: everything arrives as triage and is either scheduled, resolved or declined.',
            'statuses' => [
                ['name' => 'Triage',            'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                ['name' => 'Scheduled',         'category' => 'backlog',   'color' => '#94a3b8', 'is_default' => false],
                ['name' => 'Investigating',     'category' => 'started',   'color' => '#f59e0b', 'is_default' => false],
                ['name' => 'Waiting on client', 'category' => 'started',   'color' => '#0ea5e9', 'is_default' => false, 'is_awaiting_client' => true],
                ['name' => 'Resolved',          'category' => 'done',      'color' => '#10b981', 'is_default' => false],
                ['name' => 'Declined',          'category' => 'canceled',  'color' => '#6b7280', 'is_default' => false],
            ],
            'labels' => [
                ['name' => 'Regression', 'color' => '#ef4444', 'description' => 'Worked before. Something we shipped broke it.'],
                ['name' => 'Out of hours', 'color' => '#8b5cf6', 'description' => 'Handled outside the agreed hours.'],
                ['name' => 'Third party', 'color' => '#14b8a6', 'description' => 'The fault is in somebody else\'s service or plugin.'],
            ],
            'fields' => [
                ['name' => 'Environment', 'type' => 'select', 'options' => ['Production', 'Staging', 'Local']],
                ['name' => 'Billable', 'type' => 'checkbox'],
                ['name' => 'Client reference', 'type' => 'text'],
            ],
        ],

        /*
         * The team's own product, with nobody to report to. No waiting-on-client
         * status, because there is no client; an icebox instead, which is where most
         * of an internal backlog honestly lives.
         */
        'internal_product' => [
            'name' => 'Internal product',
            'blurb' => 'The team\'s own work: an icebox, a sized queue and a review step. No client-facing wait.',
            'statuses' => [
                ['name' => 'Icebox',      'category' => 'backlog',   'color' => '#94a3b8', 'is_default' => false],
                ['name' => 'Next up',     'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                // A limit is shown and counted, never enforced; see the board.
                ['name' => 'In progress', 'category' => 'started',   'color' => '#f59e0b', 'is_default' => false, 'wip_limit' => 5],
                ['name' => 'In review',   'category' => 'started',   'color' => '#8b5cf6', 'is_default' => false],
                ['name' => 'Shipped',     'category' => 'done',      'color' => '#10b981', 'is_default' => false],
                ['name' => 'Dropped',     'category' => 'canceled',  'color' => '#6b7280', 'is_default' => false],
            ],
            'labels' => [
                ['name' => 'Tech debt', 'color' => '#78716c', 'description' => 'Work that makes later work cheaper.'],
                ['name' => 'Papercut', 'color' => '#f472b6', 'description' => 'Small, annoying, and nobody ever schedules it.'],
                ['name' => 'Spike', 'color' => '#0ea5e9', 'description' => 'Timeboxed: the output is an answer, not a feature.'],
            ],
            'fields' => [
                ['name' => 'Size', 'type' => 'select', 'options' => ['XS', 'S', 'M', 'L']],
            ],
        ],

    ],

];
