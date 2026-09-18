<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Support\Billing\LimitExceeded;
use App\Support\Billing\Plan;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Limits only apply to the hosted service; a self-hosted install has none.
        config(['buggie.hosted' => true]);

        // Small numbers so the tests say what they mean rather than looping 100 times.
        config([
            'plans.plans.free.limits' => [
                'projects' => 2,
                'members' => 2,
                'reports_per_month' => 3,
            ],
            'plans.trial' => 'free',
        ]);
    }

    /** A workspace past its trial, so the free limits apply. */
    private function freeWorkspace(): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $workspace->forceFill(['trial_ends_at' => now()->subDay()])->save();

        return [$workspace->fresh(), $owner];
    }

    #[Test]
    public function a_workspace_on_trial_gets_the_trial_plan_then_falls_back(): void
    {
        config(['plans.trial' => 'team']);
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->assertSame('Team', $workspace->plan()->name());

        $workspace->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $this->assertSame('Free', $workspace->fresh()->plan()->name());
    }

    #[Test]
    public function the_project_limit_is_enforced(): void
    {
        [$workspace, $owner] = $this->freeWorkspace();

        app(Tenancy::class)->run($workspace, function () {
            app(CreateProject::class)->handle(['name' => 'One']);
            app(CreateProject::class)->handle(['name' => 'Two']);
        });

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/projects'), ['name' => 'Three'])
            // 402, not 403: they are allowed, they have simply run out.
            ->assertStatus(402);

        $this->assertSame(
            2,
            app(Tenancy::class)->run($workspace, fn () => Project::count()),
        );
    }

    #[Test]
    public function the_member_limit_counts_outstanding_invitations(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->freeWorkspace();

        // One member (the owner) and a limit of two, so one invitation fits.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'first@example.com',
                'role' => WorkspaceRole::Member->value,
            ])
            ->assertRedirect();

        // The second would take the workspace to three seats once accepted, so it is
        // refused now rather than at the moment someone tries to join.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'second@example.com',
                'role' => WorkspaceRole::Member->value,
            ])
            ->assertStatus(402);

        $this->assertDatabaseCount('invitations', 1);
    }

    #[Test]
    public function re_inviting_the_same_address_does_not_consume_another_seat(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->freeWorkspace();

        foreach (range(1, 3) as $_) {
            $this->actingAs($owner)
                ->post($this->workspaceUrl($workspace, '/settings/members'), [
                    'email' => 'same@example.com',
                    'role' => WorkspaceRole::Member->value,
                ])
                ->assertRedirect();
        }

        $this->assertDatabaseCount('invitations', 1);
    }

    #[Test]
    public function the_monthly_report_limit_stops_ingest_with_402(): void
    {
        Queue::fake();
        [$workspace] = $this->freeWorkspace();

        $key = app(Tenancy::class)->run($workspace, fn () => WidgetKey::factory()->create([
            'project_id' => Project::factory()->create()->id,
        ]));

        foreach (range(1, 3) as $i) {
            $this->postJson("/api/ingest/{$key->public_key}", ['title' => "report {$i}"])
                ->assertStatus(202);
        }

        $response = $this->postJson("/api/ingest/{$key->public_key}", ['title' => 'one too many'])
            ->assertStatus(402);

        // The reporter did nothing wrong, so they are told something true rather than
        // a generic failure.
        $this->assertStringContainsString('monthly report limit', $response->json('message'));
    }

    #[Test]
    public function last_months_reports_do_not_count_against_this_month(): void
    {
        Queue::fake();
        [$workspace] = $this->freeWorkspace();

        $key = app(Tenancy::class)->run($workspace, function () {
            $key = WidgetKey::factory()->create(['project_id' => Project::factory()->create()->id]);

            \App\Models\Report::factory()->count(5)->create([
                'project_id' => $key->project_id,
            ])->each(fn ($r) => $r->forceFill(['created_at' => now()->subMonth()])->save());

            return $key;
        });

        $this->assertSame(0, $workspace->fresh()->reportsThisMonth());

        $this->postJson("/api/ingest/{$key->public_key}", ['title' => 'this month'])
            ->assertStatus(202);
    }

    #[Test]
    public function an_unlimited_plan_has_no_ceiling(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        config(['plans.trial' => 'business']);

        $plan = $workspace->fresh()->plan();

        $this->assertNull($plan->limit('projects'));
        $this->assertTrue($workspace->fresh()->isWithinLimit('projects', 500));
    }

    #[Test]
    public function usage_reports_how_close_the_workspace_is(): void
    {
        [$workspace] = $this->freeWorkspace();

        app(Tenancy::class)->run($workspace, fn () => app(CreateProject::class)->handle(['name' => 'One']));

        $usage = $workspace->fresh()->usage();

        $this->assertSame(1, $usage['projects']['used']);
        $this->assertSame(2, $usage['projects']['limit']);
        $this->assertFalse($usage['projects']['over']);
        // 1 of 2 is past the 80% warning line.
        $this->assertTrue($usage['projects']['near']);
    }

    #[Test]
    public function only_the_owner_reaches_billing(): void
    {
        [$workspace] = $this->freeWorkspace();

        $admin = User::factory()->create();
        $workspace->members()->attach($admin->id, [
            'role' => WorkspaceRole::Admin->value, 'joined_at' => now(),
        ]);

        // Admins run the workspace; money is the owner's business alone.
        $this->actingAs($admin)
            ->get($this->workspaceUrl($workspace, '/settings/billing'))
            ->assertForbidden();
    }

    #[Test]
    public function an_unsubscribable_plan_cannot_be_checked_out(): void
    {
        [$workspace, $owner] = $this->freeWorkspace();

        // The free plan has no Stripe price, so there is nothing to buy.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/billing/checkout'), ['plan' => 'free'])
            ->assertStatus(422);
    }

    #[Test]
    public function limits_are_read_from_config_not_hardcoded(): void
    {
        $this->assertSame(2, Plan::find('free')->limit('projects'));

        config(['plans.plans.free.limits.projects' => 99]);

        $this->assertSame(99, Plan::find('free')->limit('projects'));
    }

    #[Test]
    public function a_limit_failure_names_what_was_exceeded(): void
    {
        $exception = LimitExceeded::projects(3);

        $this->assertSame(402, $exception->getStatusCode());
        $this->assertSame('projects', $exception->limit);
        $this->assertStringContainsString('3 projects', $exception->getMessage());
    }
}
