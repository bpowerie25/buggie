<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Models\Project;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Buggie is AGPL-3.0 and the same code runs the hosted service and somebody's own
 * server. The difference is one flag, and these tests pin down what it means: a
 * self-hosted install is not a crippled one.
 */
class SelfHostedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buggie.hosted' => false,
            // Even with mean limits configured, nothing should apply them.
            'plans.plans.free.limits' => ['projects' => 1, 'members' => 1, 'reports_per_month' => 1],
            'plans.trial' => 'free',
        ]);
    }

    #[Test]
    public function nothing_is_metered(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $workspace->forceFill(['trial_ends_at' => now()->subYear()])->save();

        $plan = $workspace->fresh()->plan();

        $this->assertSame('Self-hosted', $plan->name());

        foreach (['projects', 'members', 'reports_per_month'] as $limit) {
            $this->assertNull($plan->limit($limit), "[{$limit}] should have no ceiling.");
        }

        $this->assertTrue($workspace->fresh()->isWithinLimit('reports_per_month', 100_000));
    }

    #[Test]
    public function projects_and_reports_keep_working_past_the_hosted_limits(): void
    {
        Queue::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $workspace->forceFill(['trial_ends_at' => now()->subYear()])->save();

        // Well past the configured free limit of one.
        foreach (range(1, 4) as $i) {
            $this->actingAs($owner)
                ->post($this->workspaceUrl($workspace, '/projects'), ['name' => "Project {$i}"])
                ->assertRedirect();
        }

        $this->assertSame(4, app(Tenancy::class)->run($workspace, fn () => Project::count()));

        $key = app(Tenancy::class)->run($workspace, fn () => WidgetKey::factory()->create([
            'project_id' => Project::first()->id,
        ]));

        foreach (range(1, 5) as $i) {
            $this->postJson("/api/ingest/{$key->public_key}", ['title' => "report {$i}"])
                ->assertStatus(202);
        }
    }

    #[Test]
    public function there_is_nothing_to_bill_so_the_screens_are_not_reachable(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/billing'))
            ->assertNotFound();
    }

    #[Test]
    public function no_billing_information_is_shared_with_the_front_end(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, fn () => app(CreateProject::class)->handle(['name' => 'One']));

        // No plan name, no usage meter, no upgrade nudge anywhere in the UI.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('billing', null));
    }

    #[Test]
    public function the_install_does_not_phone_home(): void
    {
        // Sentry is opt-in and off unless a DSN is configured: an install on somebody
        // else's server must not report to us by default. An empty string counts as
        // off, which is what an untouched .env.example produces.
        $this->assertEmpty(config('sentry.dsn'));
        $this->assertEmpty(config('cashier.secret'));
    }
}
