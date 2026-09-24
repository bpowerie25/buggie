<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\RelateIssues;
use App\Enums\ProjectRole;
use App\Enums\RelationType;
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
 * A project's timeline shown to its clients: read-only, one project at a time, only
 * where the team has switched it on, and only the issues that client could open.
 */
class ClientTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $kennco;

    private Project $globex;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->workspace->update(['name' => 'Matrix']);
        $this->owner->update(['name' => 'Owen Owner']);

        [$this->kennco, $this->globex] = $this->tenant(fn () => [
            Project::factory()->create(['name' => 'Kennco', 'key' => 'KD', 'slug' => 'kennco']),
            Project::factory()->create(['name' => 'Globex', 'key' => 'GLX', 'slug' => 'globex']),
        ]);

        // Mia manages Kennco's side: every client-visible Kennco issue is hers to see.
        $this->mia = User::factory()->create(['name' => 'Mia']);
        $this->workspace->members()->attach($this->mia->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->kennco->clients()->attach($this->mia->id, ['role' => ProjectRole::ClientManager->value]);
    }

    #[Test]
    public function it_is_off_until_the_team_shows_it(): void
    {
        $this->issue($this->kennco, 'Homepage', 'client');

        $this->actingAs($this->mia)->get($this->url())->assertNotFound();
        $this->actingAs($this->mia)->get($this->workspaceUrl($this->workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('clientTimeline', false));

        $this->share($this->kennco);

        $this->actingAs($this->mia)->get($this->url())->assertOk();
        $this->actingAs($this->mia)->get($this->workspaceUrl($this->workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('clientTimeline', true));
    }

    #[Test]
    public function a_client_sees_only_what_they_could_open_and_cannot_drag_it(): void
    {
        $this->share($this->kennco);
        $shared = $this->issue($this->kennco, 'Homepage build', 'client', assignee: $this->owner, estimate: 480);
        $this->issue($this->kennco, 'Internal: refactor the CMS', 'internal');

        $props = $this->actingAs($this->mia)->get($this->url())->viewData('page')['props'];
        $raw = json_encode(collect($props)->except('ziggy')->all());

        $this->assertSame([$shared->key], collect($props['rows'])->pluck('key')->all());
        $this->assertFalse($props['editable']);
        $this->assertStringNotContainsString('refactor the CMS', $raw);

        // The team as the workspace, and no estimate.
        $row = $props['rows'][0];
        $this->assertSame('Matrix', $row['assignee']);
        $this->assertNull($row['estimate']);
        $this->assertStringNotContainsString('Owen Owner', $raw);

        $this->actingAs($this->mia)
            ->patch($this->workspaceUrl($this->workspace, "/issues/{$shared->key}/schedule"), [
                'start_on' => '2027-01-01', 'due_on' => '2027-01-02', 'version' => $shared->scheduleVersion(),
            ])->assertForbidden();
    }

    #[Test]
    public function one_project_at_a_time_and_never_one_they_do_not_hold_or_that_is_not_shared(): void
    {
        $this->share($this->kennco);
        $this->share($this->globex);
        $this->issue($this->kennco, 'Kennco work', 'client');
        $this->issue($this->globex, 'Globex secret plan', 'client');

        // Asking for Globex, or for everything, gets their own project instead.
        foreach (['project:globex', '', 'is:open'] as $q) {
            $props = $this->actingAs($this->mia)->get($this->url('?q='.urlencode($q)))->viewData('page')['props'];

            $this->assertSame(['Kennco work'], collect($props['rows'])->pluck('title')->all(), "Leaked with q=[{$q}].");
            $this->assertSame(['kennco'], collect($props['projects'])->pluck('slug')->all());
            $this->assertStringNotContainsString('Globex', json_encode(collect($props)->except('ziggy')->all()));
        }
    }

    #[Test]
    public function a_blocker_they_cannot_see_is_not_named(): void
    {
        $this->share($this->kennco);
        $theirs = $this->issue($this->kennco, 'Launch', 'client');
        $hidden = $this->issue($this->kennco, 'Internal: migrate hosting', 'internal');

        $this->tenant(fn () => app(RelateIssues::class)->handle($theirs, $hidden, RelationType::BlockedBy, $this->owner));

        $staffRow = collect($this->actingAs($this->owner)->get($this->url('?q=project:kennco'))->viewData('page')['props']['rows'])->firstWhere('key', $theirs->key);
        $this->assertSame([$hidden->key], $staffRow['blocked_by'], 'The control: staff see the blocker.');

        $props = $this->actingAs($this->mia)->get($this->url())->viewData('page')['props'];
        $this->assertSame([], $props['rows'][0]['blocked_by']);
        $this->assertStringNotContainsString($hidden->key, json_encode(collect($props)->except('ziggy')->all()));
    }

    #[Test]
    public function the_team_switches_it_on_in_project_settings(): void
    {
        $this->actingAs($this->owner)
            ->put($this->workspaceUrl($this->workspace, '/projects/kennco'), ['name' => 'Kennco', 'client_timeline' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->kennco->fresh()->showsTimelineToClients());
    }

    private function share(Project $project): void
    {
        $project->forceFill(['settings' => [...($project->settings ?? []), 'client_timeline' => true]])->save();
    }

    private function issue(Project $project, string $title, string $visibility, ?User $assignee = null, ?int $estimate = null): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle(
            $project,
            ['title' => $title, 'visibility' => $visibility, 'assignee_id' => $assignee?->id],
            $this->owner,
        ));

        $issue->forceFill([
            'start_on' => now()->addDays(2)->toDateString(),
            'due_on' => now()->addDays(9)->toDateString(),
            'estimate_minutes' => $estimate,
        ])->save();

        return $issue->fresh();
    }

    private function url(string $query = ''): string
    {
        return $this->workspaceUrl($this->workspace, '/timeline'.$query);
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
