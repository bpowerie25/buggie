<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use App\Support\Workload\Workload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Workload: each person's remaining estimates, spread over the working days of their
 * dated work, week by week and across projects, against the hours they have.
 */
class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $dana;

    private User $sam;

    private Project $web;

    private Project $shop;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, so "this week" is 5–9 October.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00'));

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->owner->update(['name' => 'Owen']);

        $this->dana = $this->staff('Dana', 20, 'Developer');
        $this->sam = $this->staff('Sam', 37.5, 'Designer');

        [$this->web, $this->shop] = $this->tenant(fn () => [
            Project::factory()->create(['name' => 'Website', 'key' => 'WEB']),
            Project::factory()->create(['name' => 'Shop', 'key' => 'SHOP']),
        ]);
    }

    #[Test]
    public function minutes_are_spread_over_working_days_and_late_work_lands_today(): void
    {
        $today = CarbonImmutable::parse('2026-10-05');
        $d = fn (string $date) => CarbonImmutable::parse($date);

        // Thursday to Tuesday: two working days in each week.
        $this->assertEquals(['2026-10-05' => 300, '2026-10-12' => 300], Workload::distribute(600, $d('2026-10-08'), $d('2026-10-13'), $today));
        // A weekend alone goes on the Monday after.
        $this->assertEquals(['2026-10-12' => 120], Workload::distribute(120, $d('2026-10-10'), $d('2026-10-11'), $today));
        // Entirely in the past, still open: it is being done now.
        $this->assertEquals(['2026-10-05' => 480], Workload::distribute(480, $d('2026-09-21'), $d('2026-09-25'), $today));
    }

    #[Test]
    public function the_grid_adds_up_each_persons_weeks_across_projects_less_what_is_already_logged(): void
    {
        // 30h over two weeks, 6h already logged: 24h left, 12h a week.
        $build = $this->issue($this->web, 'Build templates', $this->dana, '2026-10-05', '2026-10-16', 30);
        $this->log($build, $this->dana, 360);
        // Another project, same person, same week: 10h.
        $this->issue($this->shop, 'Checkout fix', $this->dana, '2026-10-06', '2026-10-06', 10);
        // Nothing to place.
        $this->issue($this->web, 'Unestimated', $this->dana, '2026-10-06', '2026-10-09');
        $this->issue($this->web, 'Undated', $this->dana, estimate: 4);

        $props = $this->page();
        $dana = collect($props['groups'])->flatMap(fn ($g) => $g['people'])->firstWhere('name', 'Dana');

        // In the order of the workspace's list of disciplines, which starts Developer, Designer.
        $this->assertSame(['Developer', 'Designer', 'No discipline set'], array_column($props['groups'], 'discipline'));
        $this->assertSame(1200, $dana['weekly_minutes']);
        $this->assertSame(22 * 60, $dana['cells']['2026-10-05']['minutes'], 'Over her 20 hours: 12 + 10.');
        $this->assertSame(12 * 60, $dana['cells']['2026-10-12']['minutes']);
        $this->assertSame(['Build templates', 'Checkout fix'], array_column($dana['cells']['2026-10-05']['issues'], 'title'));
        $this->assertSame([1, 1], [$dana['unestimated'], $dana['undated']]);

        // Narrowed to one project, the other project's work leaves her week.
        $web = collect($this->page(['project_id' => $this->web->id])['groups'])->flatMap(fn ($g) => $g['people'])->firstWhere('name', 'Dana');
        $this->assertSame(12 * 60, $web['cells']['2026-10-05']['minutes']);
    }

    #[Test]
    public function planned_work_with_nobody_on_it_has_its_own_row(): void
    {
        $this->issue($this->web, 'Nobody has this', null, '2026-10-12', '2026-10-16', 15);

        $this->assertSame(15 * 60, $this->page()['unassigned']['2026-10-12']);
    }

    #[Test]
    public function the_filters_behind_the_holes_find_the_same_issues(): void
    {
        $this->issue($this->web, 'Unestimated', $this->dana, '2026-10-06', '2026-10-09');
        $this->issue($this->web, 'Undated', $this->dana, estimate: 4);
        $this->issue($this->web, 'Planned', $this->dana, '2026-10-06', '2026-10-09', 4);

        $titles = fn (string $q) => collect($this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/issues?q='.urlencode($q)))
            ->viewData('page')['props']['issues'])->pluck('title')->all();

        $this->assertSame(['Unestimated'], $titles("assignee:{$this->dana->id} no:estimate"));
        $this->assertSame(['Undated'], $titles("assignee:{$this->dana->id} no:dates"));
    }

    #[Test]
    public function estimates_are_set_against_what_the_work_took_and_hours_against_hours_available(): void
    {
        $done = $this->issue($this->web, 'Finished', $this->dana, '2026-09-21', '2026-09-25', 10);
        $this->log($done, $this->dana, 15 * 60, '2026-09-24');
        $this->tenant(fn () => $done->forceFill([
            'status_id' => $this->web->statuses()->where('category', StatusCategory::Done->value)->firstOrFail()->id,
            'closed_at' => now()->subDays(10),
        ])->save());

        $dana = collect($this->page(['since' => 30])['actuals'])->firstWhere('name', 'Dana');

        $this->assertSame([1, 600, 900, 900], [$dana['closed'], $dana['estimated'], $dana['actual'], $dana['logged']]);
        // 20 hours a week is 4 a day, over the 21 weekdays from 6 September to 5 October.
        $this->assertSame(4 * 60 * 21, $dana['available']);
    }

    #[Test]
    public function an_admin_sets_hours_and_discipline_and_nobody_else_can(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$this->sam->id}/capacity"), ['weekly_hours' => 30, 'discipline' => 'QA'])
            ->assertSessionHasNoErrors();

        $sam = $this->workspace->members()->whereKey($this->sam->id)->first();
        $this->assertSame(['30.00', 'QA'], [$sam->pivot->weekly_hours, $sam->pivot->discipline]);

        // Only from the workspace's list.
        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$this->sam->id}/capacity"), ['weekly_hours' => 30, 'discipline' => 'Astronaut'])
            ->assertSessionHasErrors('discipline');

        $this->actingAs($this->dana)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$this->sam->id}/capacity"), ['weekly_hours' => 80])
            ->assertForbidden();

        $client = $this->client();
        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$client->id}/capacity"), ['weekly_hours' => 10])
            ->assertNotFound();
    }

    #[Test]
    public function a_client_has_no_workload_screen_and_is_not_on_one(): void
    {
        $client = $this->client();

        $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/workload'))->assertNotFound();

        $names = collect($this->page()['groups'])->flatMap(fn ($g) => $g['people'])->pluck('name')->all();
        $this->assertNotContains($client->name, $names);
    }

    /** @return array<string, mixed> */
    private function page(array $query = []): array
    {
        return $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/workload?'.http_build_query($query)))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function staff(string $name, float $hours, string $discipline): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->members()->attach($user->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
            'weekly_hours' => $hours, 'discipline' => $discipline,
        ]);

        return $user;
    }

    private function client(): User
    {
        $client = User::factory()->create(['name' => 'Cliff Client']);
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        return $client;
    }

    private function issue(Project $project, string $title, ?User $assignee, ?string $from = null, ?string $to = null, ?int $estimate = null): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($project, ['title' => $title, 'assignee_id' => $assignee?->id], $this->owner));

        $issue->forceFill([
            'start_on' => $from,
            'due_on' => $to,
            'estimate_minutes' => $estimate === null ? null : $estimate * 60,
        ])->save();

        return $issue;
    }

    private function log(Issue $issue, User $user, int $minutes, string $on = '2026-10-05'): void
    {
        $this->tenant(fn () => TimeEntry::forceCreate([
            'issue_id' => $issue->id, 'user_id' => $user->id, 'minutes' => $minutes, 'spent_on' => $on, 'billable' => true,
        ]));
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
