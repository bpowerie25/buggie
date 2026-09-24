<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Swimlanes: the timeline grouped by phase, project, person or status — and a client
 * limited to the groupings that tell them nothing new.
 */
class TimelineGroupingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $dana;

    private Project $web;

    private Project $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->workspace->update(['name' => 'Matrix']);
        $this->owner->update(['name' => 'Owen Owner']);

        $this->dana = User::factory()->create(['name' => 'Dana Scully']);
        $this->workspace->members()->attach($this->dana->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        [$this->web, $this->mobile] = $this->tenant(fn () => [
            Project::factory()->create(['name' => 'Website', 'key' => 'WEB', 'slug' => 'web']),
            Project::factory()->create(['name' => 'App', 'key' => 'APP', 'slug' => 'app']),
        ]);
    }

    #[Test]
    public function by_person_puts_each_persons_work_under_them_and_the_unassigned_last(): void
    {
        $this->issue($this->web, 'Nobody has it', from: 1);
        $this->issue($this->web, 'Owen builds', from: 2, assignee: $this->owner);
        $this->issue($this->mobile, 'Dana designs', from: 3, assignee: $this->dana);
        $this->issue($this->mobile, 'Dana tests', from: 1, assignee: $this->dana);

        $this->assertSame(
            ['Dana Scully', 'Dana tests', 'Dana designs', 'Owen Owner', 'Owen builds', 'Unassigned', 'Nobody has it'],
            array_column($this->rows('assignee'), 'title'),
        );
    }

    #[Test]
    public function by_status_reads_the_way_work_moves_and_merges_same_named_statuses(): void
    {
        $this->issue($this->web, 'Started on web', from: 1, category: StatusCategory::Started);
        $this->issue($this->mobile, 'Started on app', from: 2, category: StatusCategory::Started);
        $this->issue($this->web, 'Not started', from: 3, category: StatusCategory::Unstarted);

        $titles = array_column($this->rows('status'), 'title');
        $kinds = array_column($this->rows('status'), 'kind');

        $this->assertSame(['Not started', 'Started on web', 'Started on app'], array_values(array_diff($titles, $this->headers('status'))));
        $this->assertSame(['group', 'issue', 'group', 'issue', 'issue'], array_map(fn ($k) => $k === 'group' ? 'group' : 'issue', $kinds));
    }

    #[Test]
    public function by_project_and_none(): void
    {
        $this->issue($this->web, 'Homepage', from: 1);
        $this->issue($this->mobile, 'Login', from: 2);

        $this->assertSame(['App', 'Login', 'Website', 'Homepage'], array_column($this->rows('project'), 'title'));
        $this->assertSame(['Homepage', 'Login'], array_column($this->rows('none'), 'title'));
        $this->assertSame(['Homepage', 'Login'], array_column($this->rows('nonsense'), 'title'), 'An unknown grouping falls back to phases, which with none is flat.');
    }

    #[Test]
    public function a_client_can_group_by_phase_or_not_at_all_and_is_never_sent_the_team(): void
    {
        $this->web->forceFill(['settings' => ['client_timeline' => true]])->save();
        $this->issue($this->web, 'Homepage', from: 1, assignee: $this->dana, visibility: 'client');

        $mia = User::factory()->create(['name' => 'Mia']);
        $this->workspace->members()->attach($mia->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($mia->id, ['role' => ProjectRole::ClientManager->value]);

        foreach (['assignee', 'status'] as $group) {
            $props = $this->actingAs($mia)->get($this->workspaceUrl($this->workspace, "/timeline?group={$group}"))->viewData('page')['props'];
            $raw = json_encode(collect($props)->except('ziggy')->all());

            $this->assertSame('phase', $props['group']);
            $this->assertSame(['phase', 'none'], $props['groupings']);
            $this->assertSame(['Homepage'], array_column($props['rows'], 'title'));
            $this->assertStringNotContainsString('Dana', $raw);
            $this->assertNull($props['rows'][0]['status_color']);
            $this->assertNull($props['rows'][0]['assignee_id']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $group): array
    {
        return $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, "/timeline?group={$group}"))
            ->viewData('page')['props']['rows'];
    }

    /** @return array<int, string> */
    private function headers(string $group): array
    {
        return array_column(array_filter($this->rows($group), fn ($r) => $r['kind'] === 'group'), 'title');
    }

    private function issue(Project $project, string $title, int $from, ?User $assignee = null, string $visibility = 'internal', ?StatusCategory $category = null): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle(
            $project,
            ['title' => $title, 'visibility' => $visibility, 'assignee_id' => $assignee?->id],
            $this->owner,
        ));

        $issue->forceFill([
            'start_on' => now()->addDays($from)->toDateString(),
            'due_on' => now()->addDays($from + 2)->toDateString(),
            'status_id' => $category
                ? $this->tenant(fn () => $project->statuses()->where('category', $category->value)->orderBy('position')->firstOrFail()->id)
                : $issue->status_id,
        ])->save();

        return $issue;
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
