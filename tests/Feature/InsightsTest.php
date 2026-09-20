<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Actions\UpdateIssue;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Support\Insights\Insights;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function insights(?int $projectId = null, ?string $from = null, ?string $to = null): Insights
    {
        return new Insights(
            CarbonImmutable::parse($from ?? now()->startOfMonth()->toDateString()),
            CarbonImmutable::parse($to ?? now()->toDateString()),
            $projectId,
        );
    }

    private function doneStatus(int $projectId): Status
    {
        return Status::where('project_id', $projectId)
            ->whereIn('category', [StatusCategory::Done->value])
            ->firstOrFail();
    }

    #[Test]
    public function the_headline_counts_what_happened_in_the_window(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            $a = app(CreateIssue::class)->handle($project, ['title' => 'One'], $owner);
            app(CreateIssue::class)->handle($project, ['title' => 'Two'], $owner);

            app(UpdateIssue::class)->handle($a, ['status_id' => $this->doneStatus($project->id)->id], $owner);

            $headline = $this->insights()->headline();

            $this->assertSame(2, $headline['opened']);
            $this->assertSame(1, $headline['closed']);
            $this->assertSame(1, $headline['net'], 'The backlog grew by one.');
            $this->assertSame(1, $headline['open_now']);
        });
    }

    #[Test]
    public function collapsed_reports_are_counted(): void
    {
        // The number the product is sold on: how many reports the duplicate
        // collapsing actually absorbed.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);
            $issue = app(CreateIssue::class)->handle($project, ['title' => 'One'], $owner);

            $issue->forceFill(['occurrence_count' => 40, 'last_seen_at' => now()])->save();

            $this->assertSame(39, $this->insights()->headline()['collapsed']);
        });
    }

    #[Test]
    public function the_median_time_to_close_is_a_median(): void
    {
        // One bug that sat open for eight months drags a mean somewhere nobody
        // recognises. Three closures at 1h, 2h and 100h should report 2h.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            foreach ([1, 2, 100] as $hours) {
                $issue = app(CreateIssue::class)->handle($project, ['title' => "Took {$hours}h"], $owner);

                $issue->forceFill([
                    'created_at' => now()->subHours($hours),
                    'closed_at' => now(),
                ])->save();
            }

            $this->assertSame(120, $this->insights()->medianTimeToCloseMinutes());
        });
    }

    #[Test]
    public function nothing_closed_means_no_median_rather_than_zero(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);
            app(CreateIssue::class)->handle($project, ['title' => 'Open'], $owner);

            $this->assertNull($this->insights()->medianTimeToCloseMinutes());
        });
    }

    #[Test]
    public function throughput_walks_the_backlog_forward_from_where_it_stood(): void
    {
        // The line has to start from the truth. An issue opened before the window
        // and still open is part of the backlog on day one of the chart.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            $old = app(CreateIssue::class)->handle($project, ['title' => 'Ancient'], $owner);
            $old->forceFill(['created_at' => now()->subMonths(6)])->save();

            app(CreateIssue::class)->handle($project, ['title' => 'Today'], $owner);

            $rows = $this->insights()->throughput();

            $last = end($rows);

            $this->assertSame(2, $last['open'], 'The pre-existing issue was forgotten.');
        });
    }

    #[Test]
    public function every_bucket_in_the_range_is_present_even_when_empty(): void
    {
        // A chart that skips quiet days draws a straight line through them and
        // misreports the shape.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $insights = $this->insights(null, now()->subDays(6)->toDateString(), now()->toDateString());

            $this->assertCount(7, $insights->throughput());
        });
    }

    #[Test]
    public function a_long_range_is_bucketed_more_coarsely(): void
    {
        // A year plotted by day is 365 unreadable columns.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $this->assertSame('day', $this->insights(null, now()->subDays(20)->toDateString())->interval());
            $this->assertSame('week', $this->insights(null, now()->subDays(120)->toDateString())->interval());
            $this->assertSame('month', $this->insights(null, now()->subDays(600)->toDateString())->interval());
        });
    }

    #[Test]
    public function breakdowns_name_the_unassigned_rather_than_dropping_them(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);
            app(CreateIssue::class)->handle($project, ['title' => 'Nobody has this'], $owner);

            $rows = collect($this->insights()->byAssignee());

            $this->assertSame('Unassigned', $rows->first()['name']);
            $this->assertSame(1, $rows->first()['count']);
        });
    }

    #[Test]
    public function the_ageing_list_puts_the_oldest_first(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            $new = app(CreateIssue::class)->handle($project, ['title' => 'Recent'], $owner);
            $old = app(CreateIssue::class)->handle($project, ['title' => 'Forgotten'], $owner);
            $old->forceFill(['created_at' => now()->subDays(200)])->save();

            $rows = $this->insights()->ageing();

            $this->assertSame('Forgotten', $rows[0]['title']);
            $this->assertGreaterThanOrEqual(199, $rows[0]['days']);
        });
    }

    // -------------------------------------------------------------------- screen

    #[Test]
    public function the_screen_renders_for_staff(): void
    {
        // An Inertia assertion does not run the React component, so this proves the
        // route and the props, not the page. The page itself is checked by opening
        // it.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/insights'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights/index')
                ->has('headline')
                ->has('throughput')
                ->has('ageing'));
    }

    #[Test]
    public function a_client_cannot_reach_it(): void
    {
        // Every figure is a workspace-wide aggregate. A client scoped to two projects
        // out of twenty cannot be shown a total without being told about the other
        // eighteen.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/insights'))
            ->assertForbidden();
    }

    #[Test]
    public function a_backwards_range_is_swapped_rather_than_rejected(): void
    {
        // Easy to do with two date pickers, and the intent is never in doubt.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/insights?from='.now()->toDateString()
                .'&to='.now()->subDays(30)->toDateString()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', now()->subDays(30)->toDateString())
                ->where('filters.to', now()->toDateString()));
    }

    #[Test]
    public function nothing_leaks_across_workspaces(): void
    {
        // Every figure here is an aggregate, and an aggregate that escapes its
        // workspace is a leak with no row to point at. The median in particular is
        // computed through toBase(), which is only safe because that applies global
        // scopes first — this is the test that says so.
        [$acme, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$other, $stranger] = $this->workspaceWithMember(slug: 'other');

        app(Tenancy::class)->run($acme, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Theirs']);

            foreach (range(1, 3) as $i) {
                $issue = app(CreateIssue::class)->handle($project, ['title' => "Acme {$i}"], $owner);
                $issue->forceFill([
                    'created_at' => now()->subHours(5),
                    'closed_at' => now(),
                    'occurrence_count' => 10,
                    'last_seen_at' => now(),
                ])->save();
            }
        });

        app(Tenancy::class)->run($other, function () {
            $headline = $this->insights()->headline();

            $this->assertSame(0, $headline['opened']);
            $this->assertSame(0, $headline['closed']);
            $this->assertSame(0, $headline['open_now']);
            $this->assertSame(0, $headline['collapsed']);
            $this->assertNull($this->insights()->medianTimeToCloseMinutes());
            $this->assertSame([], $this->insights()->byProject());
            $this->assertSame([], $this->insights()->ageing());

            foreach ($this->insights()->throughput() as $row) {
                $this->assertSame(0, $row['opened']);
                $this->assertSame(0, $row['open']);
            }
        });

        // The positive control: the same figures are not zero where they belong.
        app(Tenancy::class)->run($acme, function () {
            $this->assertSame(3, $this->insights()->headline()['closed']);
            $this->assertSame(27, $this->insights()->headline()['collapsed']);
            $this->assertNotNull($this->insights()->medianTimeToCloseMinutes());
        });
    }

    #[Test]
    public function it_can_be_narrowed_to_one_project(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($owner) {
            $mine = app(CreateProject::class)->handle(['name' => 'Mine']);
            $other = app(CreateProject::class)->handle(['name' => 'Other']);

            app(CreateIssue::class)->handle($mine, ['title' => 'A'], $owner);
            app(CreateIssue::class)->handle($other, ['title' => 'B'], $owner);

            $this->assertSame(2, $this->insights()->headline()['opened']);
            $this->assertSame(1, $this->insights($mine->id)->headline()['opened']);
        });
    }
}
