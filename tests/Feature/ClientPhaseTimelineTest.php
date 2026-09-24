<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Phase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A client timeline showing the phases alone: when each stage runs and how far along
 * it is, built from internal work, with not one issue in the payload.
 */
class ClientPhaseTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['name' => 'Kennco', 'key' => 'KD', 'slug' => 'kennco']));

        $this->mia = User::factory()->create(['name' => 'Mia']);
        $this->workspace->members()->attach($this->mia->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($this->mia->id, ['role' => ProjectRole::ClientManager->value]);
    }

    #[Test]
    public function the_team_chooses_off_phases_or_issues_in_project_settings(): void
    {
        foreach (['phases' => 'phases', 'issues' => 'issues', 'off' => null, true => 'issues', false => null] as $sent => $mode) {
            $this->actingAs($this->owner)
                ->put($this->workspaceUrl($this->workspace, '/projects/kennco'), ['name' => 'Kennco', 'client_timeline' => $sent])
                ->assertSessionHasNoErrors();

            $this->assertSame($mode, $this->project->fresh()->clientTimelineMode(), "Sent {$sent}.");
        }

        $this->actingAs($this->owner)
            ->put($this->workspaceUrl($this->workspace, '/projects/kennco'), ['name' => 'Kennco', 'client_timeline' => 'everything'])
            ->assertSessionHasErrors('client_timeline');

        $this->share('phases');
        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/projects/kennco/edit'))
            ->assertInertia(fn ($page) => $page->where('clientTimeline', 'phases'));
    }

    #[Test]
    public function a_client_sees_each_phase_with_its_dates_and_progress_and_no_issue_at_all(): void
    {
        $this->share('phases');
        [$design, $build, $launch] = $this->tenant(fn () => [
            $this->project->phases()->create(['name' => 'Design', 'position' => 0]),
            $this->project->phases()->create(['name' => 'Build', 'position' => 1]),
            $this->project->phases()->create(['name' => 'Launch', 'position' => 2]),
        ]);

        // All internal: nothing is shared with Mia issue by issue.
        $this->issue('Secret wireframes', $design, 1, 4, StatusCategory::Done);
        $this->issue('Secret moodboard', $design, 3, 6);
        $this->issue('Secret rejected idea', $design, 0, 30, StatusCategory::Canceled);
        $this->issue('Secret templates', $build, 7, 20);
        $this->issue('Secret go-live checklist', $launch);

        $props = $this->actingAs($this->mia)->get($this->workspaceUrl($this->workspace, '/timeline'))->viewData('page')['props'];
        $raw = json_encode(collect($props)->except('ziggy')->all());

        $this->assertSame('phases', $props['mode']);
        $this->assertSame(['Design', 'Build'], array_column($props['rows'], 'title'));
        $this->assertSame(['Launch'], $props['unscheduled']);

        $design = $props['rows'][0];
        $this->assertSame([now()->addDays(1)->toDateString(), now()->addDays(6)->toDateString()], [$design['start'], $design['end']], 'The cancelled idea does not stretch the phase.');
        $this->assertSame(['percent' => 50], $design['progress']);

        $this->assertStringNotContainsString('Secret', $raw);
        $this->assertStringNotContainsString('KD-', $raw);
        $this->assertSame([], $props['undated']);
    }

    #[Test]
    public function staff_still_see_the_whole_plan_on_the_same_project(): void
    {
        $this->share('phases');
        $design = $this->tenant(fn () => $this->project->phases()->create(['name' => 'Design']));
        $this->issue('Wireframes', $design, 1, 4);

        $props = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/timeline'))->viewData('page')['props'];

        $this->assertSame('issues', $props['mode']);
        $this->assertSame(['Design', 'Wireframes'], array_column($props['rows'], 'title'));
    }

    private function share(string $mode): void
    {
        $this->project->forceFill(['settings' => ['client_timeline' => $mode]])->save();
    }

    private function issue(string $title, Phase $phase, ?int $from = null, ?int $to = null, ?StatusCategory $category = null): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title], $this->owner));

        $issue->forceFill([
            'phase_id' => $phase->id,
            'start_on' => $from === null ? null : now()->addDays($from)->toDateString(),
            'due_on' => $to === null ? null : now()->addDays($to)->toDateString(),
            'status_id' => $category
                ? $this->tenant(fn () => $this->project->statuses()->where('category', $category->value)->firstOrFail()->id)
                : $issue->status_id,
        ])->save();

        return $issue;
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
