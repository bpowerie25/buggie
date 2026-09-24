<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
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
 * Dragging on the timeline: dates saved from a bar, and a drag made on top of
 * somebody else's change refused rather than silently winning.
 */
class IssueScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->owner->update(['name' => 'Owen']);
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));
    }

    #[Test]
    public function a_drag_saves_the_dates_and_says_so_in_the_activity(): void
    {
        $issue = $this->issue(['start_on' => '2026-10-05', 'due_on' => '2026-10-09']);

        $this->actingAs($this->owner)
            ->patch($this->url($issue), ['start_on' => '2026-10-08', 'due_on' => '2026-10-12', 'version' => $issue->scheduleVersion()])
            ->assertSessionHasNoErrors();

        $issue = $this->reload($issue);
        $this->assertSame('2026-10-08', $issue->start_on->toDateString());
        $this->assertSame('2026-10-12', $issue->due_on->toDateString());

        $event = $issue->events()->where('type', IssueEventType::DatesChanged->value)->sole();
        $this->assertEquals(['start_on' => '2026-10-05', 'due_on' => '2026-10-09'], $event->data['from']);
        $this->assertTrue($event->is_internal);
    }

    #[Test]
    public function a_drag_on_top_of_somebody_elses_change_is_refused_and_names_them(): void
    {
        $issue = $this->issue(['start_on' => '2026-10-05', 'due_on' => '2026-10-09']);
        $loaded = $issue->scheduleVersion();

        // Somebody else moves it after the timeline was loaded.
        $colleague = User::factory()->create(['name' => 'Dana']);
        $this->workspace->members()->attach($colleague->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload($issue), ['due_on' => '2026-10-20'], $colleague));

        $this->actingAs($this->owner)
            ->patch($this->url($issue), ['start_on' => '2026-10-01', 'due_on' => '2026-10-03', 'version' => $loaded])
            ->assertSessionHasErrors(['schedule' => 'Dana changed KD-1 since you loaded the timeline, so your change was not saved. The timeline now shows it as it is.']);

        $this->assertSame('2026-10-20', $this->reload($issue)->due_on->toDateString(), "Dana's change was overwritten.");
    }

    #[Test]
    public function an_undated_issue_dropped_on_a_day_gets_that_day(): void
    {
        $issue = $this->issue();

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/timeline?q=is:open'))
            ->assertInertia(fn ($page) => $page->where('undated.0.version', $issue->scheduleVersion()));

        $this->actingAs($this->owner)
            ->patch($this->url($issue), ['start_on' => '2026-10-14', 'due_on' => '2026-10-14', 'version' => $issue->scheduleVersion()])
            ->assertSessionHasNoErrors();

        $issue = $this->reload($issue);
        $this->assertSame('2026-10-14', $issue->start_on->toDateString());
        $this->assertSame('2026-10-14', $issue->due_on->toDateString());
    }

    #[Test]
    public function a_due_date_before_the_start_is_refused(): void
    {
        $issue = $this->issue(['start_on' => '2026-10-05', 'due_on' => '2026-10-09']);

        $this->actingAs($this->owner)
            ->patch($this->url($issue), ['start_on' => '2026-10-09', 'due_on' => '2026-10-01', 'version' => $issue->scheduleVersion()])
            ->assertSessionHasErrors('due_on');
    }

    #[Test]
    public function only_staff_can_drag(): void
    {
        $issue = $this->issue(['start_on' => '2026-10-05', 'due_on' => '2026-10-09', 'visibility' => 'client']);

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)
            ->patch($this->url($issue), ['start_on' => '2026-11-01', 'due_on' => '2026-11-02', 'version' => $issue->scheduleVersion()])
            ->assertForbidden();

        $this->assertSame('2026-10-05', $this->reload($issue)->start_on->toDateString());
    }

    #[Test]
    public function the_timeline_sends_each_bar_its_version(): void
    {
        $issue = $this->issue(['start_on' => now()->toDateString(), 'due_on' => now()->addDays(3)->toDateString()]);

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/timeline'))
            ->assertInertia(fn ($page) => $page->where('rows.0.key', $issue->key)->where('rows.0.version', $issue->scheduleVersion()));
    }

    private function issue(array $attributes = []): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle(
            $this->project,
            ['title' => 'Build the contact form', ...array_diff_key($attributes, array_flip(['start_on', 'due_on']))],
            $this->owner,
        ));

        if (isset($attributes['start_on']) || isset($attributes['due_on'])) {
            $issue->forceFill(array_intersect_key($attributes, array_flip(['start_on', 'due_on'])))->save();
        }

        return $this->reload($issue);
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::findOrFail($issue->id));
    }

    private function url(Issue $issue): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$issue->key}/schedule");
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
