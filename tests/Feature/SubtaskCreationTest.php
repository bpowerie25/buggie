<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
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
 * "New subtask" on an issue: created under it in one step, or not created at all.
 */
class SubtaskCreationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));
    }

    #[Test]
    public function a_subtask_is_created_under_its_parent_and_the_page_stays_put(): void
    {
        $parent = $this->issue('Build the contact page');

        $this->actingAs($this->owner)
            ->from($this->workspaceUrl($this->workspace, "/issues/{$parent->key}"))
            ->post($this->workspaceUrl($this->workspace, '/issues'), [
                'project_id' => $this->project->id,
                'title' => 'Validate the email field',
                'parent' => strtolower($parent->key),
                'stay' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->workspaceUrl($this->workspace, "/issues/{$parent->key}"));

        $child = $this->tenant(fn () => Issue::where('title', 'Validate the email field')->sole());
        $this->assertSame($parent->id, $child->parent_id);

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, "/issues/{$parent->key}"))
            ->assertInertia(fn ($page) => $page->where('children.0.key', $child->key));
    }

    #[Test]
    public function a_refused_parent_leaves_no_issue_behind(): void
    {
        $parent = $this->issue('Parent');
        $child = $this->issue('Already a subtask');
        $this->tenant(fn () => $child->forceFill(['parent_id' => $parent->id])->save());

        $before = $this->tenant(fn () => Issue::count());

        foreach ([$child->key => 'already a subtask', 'KD-999' => 'No issue with that key'] as $key => $reason) {
            $this->actingAs($this->owner)
                ->post($this->workspaceUrl($this->workspace, '/issues'), [
                    'project_id' => $this->project->id, 'title' => 'Orphan', 'parent' => $key, 'stay' => true,
                ])
                ->assertSessionHasErrors('parent');

            $this->assertStringContainsString($reason, session('errors')->first('parent'));
        }

        $this->assertSame($before, $this->tenant(fn () => Issue::count()));
    }

    #[Test]
    public function a_client_cannot_hang_new_work_under_an_issue_and_is_not_told_whether_it_exists(): void
    {
        $parent = $this->issue('Homepage', visibility: 'client');

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        $before = $this->tenant(fn () => Issue::count());

        $this->actingAs($client)
            ->post($this->workspaceUrl($this->workspace, '/issues'), [
                'project_id' => $this->project->id, 'title' => 'Mine', 'parent' => $parent->key,
            ])
            ->assertSessionHasErrors(['parent' => 'No issue with that key in this workspace.']);

        // Refused in exactly the words a made-up key gets: only staff arrange the
        // hierarchy, and a refusal that differed between real and invented keys
        // would be a way to list them.
        $this->assertSame($before, $this->tenant(fn () => Issue::count()));
    }

    private function issue(string $title, string $visibility = 'internal'): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title, 'visibility' => $visibility], $this->owner));
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
