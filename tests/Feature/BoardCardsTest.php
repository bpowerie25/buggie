<?php

namespace Tests\Feature;

use App\Actions\UpdateIssue;
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
 * What the board keeps on screen that the list does not: cards finished in the last
 * fortnight, and pinned cards whatever the filter says.
 */
class BoardCardsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $web;

    private Project $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');

        [$this->web, $this->mobile] = $this->tenant(fn () => [
            Project::factory()->create(['key' => 'WEB', 'slug' => 'web']),
            Project::factory()->create(['key' => 'APP', 'slug' => 'app']),
        ]);
    }

    #[Test]
    public function a_card_just_finished_stays_on_the_board_but_not_on_the_list(): void
    {
        $open = $this->issue($this->web, 'Still going');
        $justDone = $this->issue($this->web, 'Finished on Monday', closedDaysAgo: 3);
        $longDone = $this->issue($this->web, 'Finished last month', closedDaysAgo: 30);

        $this->assertSame(['Still going', 'Finished on Monday'], $this->titles('board'));
        $this->assertSame(['Still going'], $this->titles('list'));

        // Asking for closed work explicitly is a different question, answered in full.
        $this->assertEqualsCanonicalizing(['Finished on Monday', 'Finished last month'], $this->titles('board', 'is:closed'));
    }

    #[Test]
    public function a_pinned_card_is_always_on_the_board(): void
    {
        $pinned = $this->issue($this->web, 'Release checklist', closedDaysAgo: 60);
        $this->pin($pinned);
        $this->issue($this->web, 'Ordinary work');

        // Whatever the filter says: closed long ago, no matching text.
        $this->assertContains('Release checklist', $this->titles('board'));
        $this->assertContains('Release checklist', $this->titles('board', 'nothing-matches-this'));
        $this->assertNotContains('Release checklist', $this->titles('list'));

        // Except the project: another project's board is not where it belongs.
        $this->assertNotContains('Release checklist', $this->titles('board', 'project:app'));
        $this->assertContains('Release checklist', $this->titles('board', 'project:web'));

        $this->actingAs($this->owner)->get($this->url('/issues?layout=board'))
            ->assertInertia(fn ($page) => $page->where('issues', fn ($issues) => collect($issues)->firstWhere('title', 'Release checklist')['pinned'] === true));
    }

    #[Test]
    public function pinning_never_shows_a_client_what_they_cannot_see(): void
    {
        $internal = $this->issue($this->web, 'Internal pinned note');
        $this->pin($internal);

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)->get($this->url('/issues?layout=board'))
            ->assertInertia(fn ($page) => $page->where('issues', []));
    }

    #[Test]
    public function staff_pin_and_unpin_and_a_client_cannot(): void
    {
        $issue = $this->issue($this->web, 'Pin me', visibility: 'client');

        $this->actingAs($this->owner)->patch($this->url("/issues/{$issue->key}"), ['board_pinned' => true])->assertRedirect();
        $this->assertNotNull($issue->fresh()->board_pinned_at);

        $this->actingAs($this->owner)->patch($this->url("/issues/{$issue->key}"), ['board_pinned' => false]);
        $this->assertNull($issue->fresh()->board_pinned_at);

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)->patch($this->url("/issues/{$issue->key}"), ['board_pinned' => true])->assertForbidden();
        $this->assertNull($issue->fresh()->board_pinned_at);
    }

    #[Test]
    public function a_card_added_on_the_board_lands_in_its_column_and_stays_on_the_board(): void
    {
        $building = $this->web->statuses()->where('category', 'started')->firstOrFail();

        $this->actingAs($this->owner)
            ->from($this->url('/issues?layout=board'))
            ->post($this->url('/issues'), [
                'project_id' => $this->web->id,
                'title' => 'Swap the hero image',
                'status_id' => $building->id,
                'type' => 'task',
                'priority' => 0,
                'visibility' => 'internal',
                'stay' => true,
            ])
            ->assertRedirect($this->url('/issues?layout=board'))
            ->assertSessionHas('success');

        $issue = $this->tenant(fn () => Issue::where('title', 'Swap the hero image')->sole());
        $this->assertSame($building->id, $issue->status_id);
        $this->assertContains('Swap the hero image', $this->titles('board'));
    }

    /** @return array<int, string> */
    private function titles(string $layout, string $query = ''): array
    {
        $props = $this->actingAs($this->owner)
            ->get($this->url('/issues?layout='.$layout.'&q='.urlencode($query)))
            ->viewData('page')['props'];

        return collect($props['issues'])->pluck('title')->all();
    }

    private function issue(Project $project, string $title, ?int $closedDaysAgo = null, string $visibility = 'internal'): Issue
    {
        return $this->tenant(function () use ($project, $title, $closedDaysAgo, $visibility) {
            $status = $closedDaysAgo === null
                ? $project->defaultStatus()
                : $project->statuses()->where('category', 'done')->firstOrFail();

            return Issue::factory()->create([
                'project_id' => $project->id,
                'title' => $title,
                'status_id' => $status->id,
                'visibility' => $visibility,
                'closed_at' => $closedDaysAgo === null ? null : now()->subDays($closedDaysAgo),
            ]);
        });
    }

    private function pin(Issue $issue): void
    {
        $this->tenant(fn () => app(UpdateIssue::class)->handle($issue, ['board_pinned' => true], $this->owner));
    }

    private function url(string $path): string
    {
        return $this->workspaceUrl($this->workspace, $path);
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
