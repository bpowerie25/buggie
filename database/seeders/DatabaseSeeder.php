<?php

namespace Database\Seeders;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Actions\CreateWorkspace;
use App\Actions\RelateIssues;
use App\Jobs\ProcessIncomingReport;
use App\Actions\UpdateIssue;
use App\Enums\IssuePriority;
use App\Enums\IssueVisibility;
use App\Enums\RelationType;
use App\Enums\WorkspaceRole;
use App\Models\Label;
use App\Models\Project;
use App\Models\Invitation;
use App\Models\PortalToken;
use App\Models\Report;
use App\Models\SavedView;
use App\Models\WidgetKey;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'brian@example.com'],
            ['name' => 'Brian', 'password' => 'password'],
        );

        $dev = User::firstOrCreate(
            ['email' => 'dev@example.com'],
            ['name' => 'Sam Rivera', 'password' => 'password'],
        );

        $client = User::firstOrCreate(
            ['email' => 'client@example.com'],
            ['name' => 'Jo Patel', 'password' => 'password'],
        );

        $workspace = app(CreateWorkspace::class)->handle($owner, 'Acme Ltd', 'acme');

        $workspace->members()->attach($dev->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($owner, $dev, $client) {
            $labels = collect([
                ['name' => 'regression', 'color' => '#ef4444'],
                ['name' => 'needs-repro', 'color' => '#f59e0b'],
                ['name' => 'accessibility', 'color' => '#8b5cf6'],
                ['name' => 'performance', 'color' => '#0ea5e9'],
            ])->map(fn (array $attrs) => Label::create($attrs));

            $site = app(CreateProject::class)->handle([
                'name' => 'Marketing Site',
                'key' => 'MS',
                'description' => 'The public website and blog.',
            ]);

            $portal = app(CreateProject::class)->handle([
                'name' => 'Customer Portal',
                'key' => 'CP',
                'description' => 'Logged-in account area.',
            ]);

            $portal->clients()->attach($client->id, ['role' => 'client']);

            $checkout = app(CreateIssue::class)->handle($portal, [
                'title' => 'Checkout button does nothing on Safari',
                'description' => $this->doc(
                    'Clicking "Pay now" on Safari 17 does nothing. No network request '
                    .'is made and there is no console error. Works in Chrome and Firefox.'
                ),
                'priority' => IssuePriority::Urgent->value,
                'assignee_id' => $dev->id,
                'visibility' => IssueVisibility::Client->value,
                'labels' => [$labels[0]->id],
            ], $client);

            app(AddComment::class)->handle($checkout, [
                'body' => $this->doc('Reproduced. It is the event listener on the form, not the button.'),
                'is_internal' => true,
            ], $dev);

            app(AddComment::class)->handle($checkout, [
                'body' => $this->doc('Thanks for reporting — we have reproduced it and are on it.'),
                'is_internal' => false,
            ], $owner);

            $inProgress = $portal->statuses()->where('category', 'started')->first();
            app(UpdateIssue::class)->handle($checkout, ['status_id' => $inProgress->id], $dev);

            $invoice = app(CreateIssue::class)->handle($portal, [
                'title' => 'Invoice PDF renders totals as 0.00',
                'priority' => IssuePriority::High->value,
                'visibility' => IssueVisibility::Client->value,
                'labels' => [$labels[0]->id],
            ], $client);

            app(RelateIssues::class)->handle($invoice, $checkout, RelationType::RelatesTo, $owner);

            app(CreateIssue::class)->handle($portal, [
                'title' => 'Session expires after 5 minutes instead of 2 hours',
                'priority' => IssuePriority::Medium->value,
                'assignee_id' => $dev->id,
            ], $owner);

            app(CreateIssue::class)->handle($site, [
                'title' => 'Pricing page images are not lazy loaded',
                'priority' => IssuePriority::Low->value,
                'labels' => [$labels[3]->id],
            ], $owner);

            app(CreateIssue::class)->handle($site, [
                'title' => 'Contact form labels are not associated with inputs',
                'description' => $this->doc('Screen readers announce the fields as unlabelled.'),
                'priority' => IssuePriority::Medium->value,
                'type' => 'task',
                'labels' => [$labels[2]->id],
            ], $owner);

            $stale = app(CreateIssue::class)->handle($site, [
                'title' => 'Blog RSS feed has the wrong timezone',
                'priority' => IssuePriority::Low->value,
            ], $owner);

            $done = $site->statuses()->where('category', 'done')->first();
            app(UpdateIssue::class)->handle($stale, ['status_id' => $done->id], $owner);

            // A widget key per project, plus an inbox with something in it.
            foreach ([$site, $portal] as $project) {
                WidgetKey::create([
                    'project_id' => $project->id,
                    'allowed_origins' => [],
                    'mode' => 'identified',
                ]);
            }

            $stack = "TypeError: Cannot read properties of null (reading 'total')\n"
                ."    at calcTotal (https://acme.test/assets/checkout-a1b2c3.js:212:19)\n"
                ."    at onSubmit (https://acme.test/assets/checkout-a1b2c3.js:88:5)";

            // Three arrivals of the same bug: they share a fingerprint and collapse to
            // one inbox row.
            foreach ([['Ana Silva', 'ana@shopper.test', 4102], ['Tom Reed', 'tom@shopper.test', 5517], [null, null, 7781]] as [$name, $email, $order]) {
                Report::create([
                    'project_id' => $portal->id,
                    'title' => 'Pay now button does nothing',
                    'body' => 'I click Pay now and the page just sits there.',
                    'reporter_name' => $name,
                    'reporter_email' => $email,
                    'environment' => [
                        'url' => "https://acme.test/orders/{$order}/checkout",
                        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                        'viewport' => '1440x900',
                        'release' => '2026.09.18-a1c3',
                    ],
                    'console' => [
                        ['level' => 'error', 'message' => "Cannot read properties of null (reading 'total')", 'at' => now()->timestamp],
                        ['level' => 'warn', 'message' => 'Deprecated payment API in use', 'at' => now()->timestamp],
                    ],
                    'network' => [
                        ['method' => 'POST', 'url' => 'https://acme.test/api/cart/total', 'status' => 500, 'duration' => 412],
                        ['method' => 'GET', 'url' => 'https://acme.test/api/cart', 'status' => 200, 'duration' => 88],
                    ],
                    'error' => [
                        'message' => "Cannot read properties of null (reading 'total') for order {$order}",
                        'stack' => $stack,
                    ],
                ]);
            }

            // Run the seeded reports through the fingerprinter, exactly as the ingest
            // endpoint would. Without this the demo inbox shows three identical rows
            // instead of one with a count — which is the whole point of the feature.
            foreach (Report::awaitingTriage()->get() as $report) {
                ProcessIncomingReport::dispatchSync($report->id, $report->workspace_id);
            }

            // And one human-written report with no error, which never auto-groups.
            Report::create([
                'project_id' => $site->id,
                'title' => 'Pricing page is unreadable on my phone',
                'body' => 'The comparison table runs off the side of the screen on an iPhone SE.',
                'reporter_name' => 'Priya Nair',
                'reporter_email' => 'priya@example.test',
                'environment' => [
                    'url' => 'https://acme.test/pricing',
                    'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Safari/604.1',
                    'viewport' => '375x667',
                ],
            ]);

            // A pending invitation and a reporter with portal access, so both
            // client-facing surfaces have something to show.
            Invitation::create([
                'email' => 'newdev@example.com',
                'role' => \App\Enums\WorkspaceRole::Member->value,
                'invited_by_id' => $owner->id,
            ]);

            PortalToken::create([
                'issue_id' => $checkout->id,
                'email' => 'ana@shopper.test',
                'token' => 'demo-portal-token-for-local-development-only',
            ]);

            foreach ([
                ['name' => 'My work', 'query' => 'is:open assignee:@me', 'user_id' => $owner->id],
                ['name' => 'Needs triage', 'query' => 'is:open no:assignee', 'user_id' => null],
                ['name' => 'Regressions', 'query' => 'is:open label:regression', 'user_id' => null],
                ['name' => 'Portal board', 'query' => 'is:open project:customer-portal',
                    'user_id' => null, 'layout' => 'board'],
            ] as $position => $view) {
                SavedView::create([
                    ...$view,
                    'created_by_id' => $owner->id,
                    'position' => $position,
                ]);
            }
        });

        // A second workspace, so a tenancy leak is visible by eye in development.
        $other = User::firstOrCreate(
            ['email' => 'someone@globex.test'],
            ['name' => 'Globex Admin', 'password' => 'password'],
        );

        $globex = app(CreateWorkspace::class)->handle($other, 'Globex', 'globex');

        app(Tenancy::class)->run($globex, function () use ($other) {
            $project = app(CreateProject::class)->handle([
                'name' => 'Internal Tools',
                'key' => 'INT',
            ]);

            app(CreateIssue::class)->handle($project, [
                'title' => 'SHOULD NEVER APPEAR IN ACME',
            ], $other);
        });

        $this->command->newLine();
        $this->command->info('acme.buggy.localhost:8080');
        $this->command->line('  brian@example.com  / password   (owner)');
        $this->command->line('  dev@example.com    / password   (member)');
        $this->command->line('  client@example.com / password   (client — Customer Portal only)');
        $this->command->info('globex.buggy.localhost:8080');
        $this->command->line('  someone@globex.test / password');
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }
}
