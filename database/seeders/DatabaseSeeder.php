<?php

namespace Database\Seeders;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Actions\CreateWorkspace;
use App\Actions\RelateIssues;
use App\Actions\UpdateIssue;
use App\Enums\IssuePriority;
use App\Enums\IssueVisibility;
use App\Enums\RelationType;
use App\Enums\WorkspaceRole;
use App\Models\Label;
use App\Models\Project;
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
