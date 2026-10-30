<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sidebar's project choice: a view filter that follows you between pages and
 * grants nothing.
 */
class ProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: array<string, Project>} */
    private function agency(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $projects = app(Tenancy::class)->run($workspace, function () {
            $web = Project::factory()->create(['key' => 'WEB', 'slug' => 'web', 'name' => 'Web']);
            $app = Project::factory()->create(['key' => 'APP', 'slug' => 'app', 'name' => 'App']);

            Issue::factory()->create(['project_id' => $web->id, 'title' => 'Web bug']);
            Issue::factory()->create(['project_id' => $app->id, 'title' => 'App bug']);

            return ['web' => $web, 'app' => $app];
        });

        return [$workspace, $staff, $projects];
    }

    private function titles($response): array
    {
        $titles = [];
        $response->assertInertia(function ($page) use (&$titles) {
            $titles = collect($page->toArray()['props']['issues'])->pluck('title')->sort()->values()->all();
        });

        return $titles;
    }

    #[Test]
    public function choosing_a_project_narrows_the_issue_list_and_clearing_it_restores_everything(): void
    {
        [$workspace, $staff] = $this->agency();
        $this->actingAs($staff);

        $this->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'web'])->assertRedirect();

        $this->assertSame(['Web bug'], $this->titles($this->get($this->workspaceUrl($workspace, '/issues'))));

        $this->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => ''])->assertRedirect();

        $this->assertSame(['App bug', 'Web bug'], $this->titles($this->get($this->workspaceUrl($workspace, '/issues'))));
    }

    #[Test]
    public function the_page_says_when_the_scope_is_narrowing_it(): void
    {
        [$workspace, $staff] = $this->agency();
        $this->actingAs($staff)->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'app']);

        $this->get($this->workspaceUrl($workspace, '/issues'))
            ->assertInertia(fn ($page) => $page->where('scopedTo.slug', 'app'));

        // An explicit project in the query wins, and then nothing is being hidden.
        $this->get($this->workspaceUrl($workspace, '/issues?q=project:web'))
            ->assertInertia(fn ($page) => $page->where('scopedTo', null)->has('issues', 1)->where('issues.0.title', 'Web bug'));
    }

    #[Test]
    public function the_scope_follows_the_triage_badge_and_the_dashboard(): void
    {
        [$workspace, $staff, $projects] = $this->agency();

        app(Tenancy::class)->run($workspace, function () use ($projects) {
            \App\Models\Report::factory()->create(['project_id' => $projects['web']->id]);
            \App\Models\Report::factory()->count(2)->create(['project_id' => $projects['app']->id]);
        });

        $this->actingAs($staff);

        $this->get($this->workspaceUrl($workspace, '/'))->assertInertia(fn ($page) => $page->where('inboxCount', 3));

        $this->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'web']);

        $this->get($this->workspaceUrl($workspace, '/'))->assertInertia(fn ($page) => $page
            ->where('inboxCount', 1)
            ->has('projects', 1)
            ->where('projectScope.current', 'web'));
    }

    #[Test]
    public function triage_can_still_ask_for_every_project_while_scoped(): void
    {
        [$workspace, $staff, $projects] = $this->agency();

        app(Tenancy::class)->run($workspace, function () use ($projects) {
            \App\Models\Report::factory()->create(['project_id' => $projects['web']->id]);
            \App\Models\Report::factory()->create(['project_id' => $projects['app']->id]);
        });

        $this->actingAs($staff)->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'web']);

        $this->get($this->workspaceUrl($workspace, '/inbox'))->assertInertia(fn ($page) => $page->has('reports', 1));
        $this->get($this->workspaceUrl($workspace, '/inbox?project=all'))->assertInertia(fn ($page) => $page->has('reports', 2));
    }

    #[Test]
    public function a_client_cannot_scope_to_a_project_they_do_not_hold(): void
    {
        [$workspace, , $projects] = $this->agency();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $projects['web']->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)
            ->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'app'])
            ->assertNotFound();
    }

    #[Test]
    public function a_stored_scope_stops_applying_when_the_project_is_no_longer_visible(): void
    {
        [$workspace, , $projects] = $this->agency();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $projects['web']->clients()->attach($client->id, ['role' => 'client_manager']);
        $projects['app']->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)->put($this->workspaceUrl($workspace, '/project-scope'), ['project' => 'app'])->assertRedirect();

        $projects['app']->clients()->detach($client->id);

        $this->get($this->workspaceUrl($workspace, '/issues'))
            ->assertInertia(fn ($page) => $page->where('scopedTo', null));
    }
}
