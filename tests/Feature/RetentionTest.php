<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\PortalToken;
use App\Models\Project;
use App\Models\Report;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bug reports collect personal data as a side effect of being useful. Keeping it for
 * ever is neither necessary nor defensible, so it ages out — while the issues and
 * comments that are the actual work product never do.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function old_screenshots_are_deleted_but_the_report_survives(): void
    {
        Storage::fake('local');
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$old, $recent] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();

            $old = Report::factory()->create([
                'project_id' => $project->id,
                'screenshot_path' => 'shots/old.jpg',
            ]);
            $old->forceFill(['created_at' => now()->subDays(200)])->save();

            $recent = Report::factory()->create([
                'project_id' => $project->id,
                'screenshot_path' => 'shots/recent.jpg',
            ]);

            return [$old, $recent];
        });

        Storage::disk('local')->put('shots/old.jpg', 'x');
        Storage::disk('local')->put('shots/recent.jpg', 'x');

        $this->artisan('buggy:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('shots/old.jpg');
        Storage::disk('local')->assertExists('shots/recent.jpg');

        // The report itself stays — only the image goes.
        $this->assertNull($old->fresh()->screenshot_path);
        $this->assertNotNull($old->fresh()->title);
        $this->assertSame('shots/recent.jpg', $recent->fresh()->screenshot_path);
    }

    #[Test]
    public function reporter_identities_are_scrubbed_after_the_window(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $report = app(Tenancy::class)->run($workspace, function () {
            $report = Report::factory()->create([
                'project_id' => Project::factory()->create()->id,
                'reporter_email' => 'ana@shopper.test',
                'reporter_name' => 'Ana Silva',
                'reporter_ref' => '4821',
                'ip_hash' => str_repeat('a', 64),
                'environment' => [
                    'url' => 'https://acme.test/checkout',
                    'identity' => ['email' => 'ana@shopper.test', 'id' => 4821],
                ],
            ]);
            $report->forceFill(['created_at' => now()->subDays(200)])->save();

            return $report;
        });

        $this->artisan('buggy:prune')->assertSuccessful();

        $report->refresh();

        foreach (['reporter_email', 'reporter_name', 'reporter_ref', 'ip_hash'] as $field) {
            $this->assertNull($report->{$field}, "[{$field}] should have been scrubbed.");
        }

        // The identity block inside the captured environment goes too — it is the
        // same data by another route.
        $this->assertArrayNotHasKey('identity', $report->environment);

        // What made the report useful is kept.
        $this->assertSame('https://acme.test/checkout', $report->environment['url']);
        $this->assertStringNotContainsString('ana@shopper.test', json_encode($report->toArray()));
    }

    #[Test]
    public function spam_and_discarded_reports_are_deleted_outright(): void
    {
        Storage::fake('local');
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = Project::factory()->create();

            foreach ([ReportState::Spam, ReportState::Discarded] as $state) {
                Report::factory()->create([
                    'project_id' => $project->id,
                    'state' => $state->value,
                    'screenshot_path' => 'shots/'.$state->value.'.jpg',
                ])->forceFill([
                    'triaged_at' => now()->subDays(45),
                    'triaged_by_id' => $user->id,
                ])->save();
            }

            // Recently dismissed, so still recoverable.
            Report::factory()->create([
                'project_id' => $project->id,
                'state' => ReportState::Spam->value,
            ])->forceFill(['triaged_at' => now()->subDay()])->save();
        });

        Storage::disk('local')->put('shots/spam.jpg', 'x');

        $this->artisan('buggy:prune')->assertSuccessful();

        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing('shots/spam.jpg');
    }

    #[Test]
    public function promoted_reports_are_never_deleted(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            Report::factory()->create([
                'project_id' => Project::factory()->create()->id,
                'state' => ReportState::Promoted->value,
            ])->forceFill([
                'created_at' => now()->subYears(2),
                'triaged_at' => now()->subYears(2),
            ])->save();
        });

        $this->artisan('buggy:prune')->assertSuccessful();

        // It became an issue; the link between the two is worth keeping.
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
    }

    #[Test]
    public function issues_and_comments_are_never_pruned(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $issue = Issue::factory()->create([
                'project_id' => Project::factory()->create()->id,
            ]);
            $issue->forceFill(['created_at' => now()->subYears(3)])->save();

            $issue->comments()->create([
                'user_id' => $user->id,
                'body' => ['type' => 'doc', 'content' => []],
                'body_text' => 'years old',
                'is_internal' => true,
            ])->forceFill(['created_at' => now()->subYears(3)])->save();
        });

        $this->artisan('buggy:prune')->assertSuccessful();

        // The tracker's own history is the product; it does not age out.
        $this->assertSame(1, Issue::withoutGlobalScopes()->count());
        $this->assertSame(1, Comment::withoutGlobalScopes()->count());
    }

    #[Test]
    public function long_expired_portal_links_are_removed(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);

            PortalToken::create([
                'issue_id' => $issue->id,
                'email' => 'gone@shopper.test',
                'expires_at' => now()->subDays(60),
            ]);

            PortalToken::create([
                'issue_id' => $issue->id,
                'email' => 'recent@shopper.test',
                'expires_at' => now()->subDays(5),
            ]);

            PortalToken::create([
                'issue_id' => $issue->id,
                'email' => 'live@shopper.test',
                'expires_at' => now()->addDays(30),
            ]);
        });

        $this->artisan('buggy:prune')->assertSuccessful();

        $remaining = PortalToken::withoutGlobalScopes()->pluck('email')->sort()->values()->all();

        $this->assertSame(['live@shopper.test', 'recent@shopper.test'], $remaining);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        Storage::fake('local');
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            Report::factory()->create([
                'project_id' => Project::factory()->create()->id,
                'reporter_email' => 'ana@shopper.test',
                'screenshot_path' => 'shots/old.jpg',
            ])->forceFill(['created_at' => now()->subDays(200)])->save();
        });

        Storage::disk('local')->put('shots/old.jpg', 'x');

        $this->artisan('buggy:prune --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('shots/old.jpg');
        $this->assertSame(
            'ana@shopper.test',
            Report::withoutGlobalScopes()->first()->reporter_email,
        );
    }

    #[Test]
    public function a_rule_set_to_zero_is_disabled(): void
    {
        config(['buggy.retention.screenshots' => 0]);
        Storage::fake('local');

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            Report::factory()->create([
                'project_id' => Project::factory()->create()->id,
                'screenshot_path' => 'shots/keep.jpg',
            ])->forceFill(['created_at' => now()->subYears(5)])->save();
        });

        Storage::disk('local')->put('shots/keep.jpg', 'x');

        $this->artisan('buggy:prune')->assertSuccessful();

        Storage::disk('local')->assertExists('shots/keep.jpg');
    }

    #[Test]
    public function pruning_crosses_every_workspace(): void
    {
        [$acme] = $this->workspaceWithMember(slug: 'acme');
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        foreach ([$acme, $globex] as $workspace) {
            app(Tenancy::class)->run($workspace, function () {
                Report::factory()->create([
                    'project_id' => Project::factory()->create()->id,
                    'reporter_email' => 'someone@shopper.test',
                ])->forceFill(['created_at' => now()->subDays(200)])->save();
            });
        }

        // Housekeeping is not a tenant operation; it runs everywhere or it is useless.
        $this->artisan('buggy:prune')->assertSuccessful();

        $this->assertSame(
            0,
            Report::withoutGlobalScopes()->whereNotNull('reporter_email')->count(),
        );
    }
}
