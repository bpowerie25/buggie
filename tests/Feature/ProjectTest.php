<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_project_seeds_the_default_workflow(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/projects'), [
                'name' => 'Marketing Site',
                'key' => 'MS',
                'description' => 'The public site.',
            ])
            ->assertRedirect();

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::with('statuses')->firstOrFail(),
        );

        $this->assertSame('MS', $project->key);
        $this->assertCount(6, $project->statuses);
        $this->assertSame(
            ['Backlog', 'Todo', 'In Progress', 'In Review', 'Done', "Won't Fix"],
            $project->statuses->pluck('name')->all(),
        );

        // Exactly one default, and it is an open category.
        $default = $project->statuses->firstWhere('is_default', true);
        $this->assertNotNull($default);
        $this->assertTrue($default->category->isOpen());
    }

    #[Test]
    public function statuses_inherit_the_projects_workspace(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)->post($this->workspaceUrl($workspace, '/projects'), [
            'name' => 'Marketing Site',
        ]);

        $this->assertDatabaseMissing('statuses', ['workspace_id' => null]);
        $this->assertSame(
            6,
            \App\Models\Status::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->count(),
        );
    }

    #[Test]
    public function the_issue_key_is_suggested_from_the_project_name(): void
    {
        [$workspace] = $this->workspaceWithMember();

        $suggestions = app(Tenancy::class)->run($workspace, fn () => [
            (new CreateProject)->suggestKey('Marketing Site'),
            (new CreateProject)->suggestKey('GAA Website Redesign'),
            (new CreateProject)->suggestKey('Invoicer'),
        ]);

        $this->assertSame(['MS', 'GWR', 'INV'], $suggestions);
    }

    #[Test]
    public function issue_keys_must_be_unique_within_a_workspace_but_not_across_them(): void
    {
        [$acme, $acmeUser] = $this->workspaceWithMember(slug: 'acme');
        [$globex, $globexUser] = $this->workspaceWithMember(slug: 'globex');

        $this->actingAs($acmeUser)
            ->post($this->workspaceUrl($acme, '/projects'), ['name' => 'Web', 'key' => 'WEB'])
            ->assertRedirect();

        // Same key again in the same workspace: rejected.
        $this->actingAs($acmeUser)
            ->post($this->workspaceUrl($acme, '/projects'), ['name' => 'Web Two', 'key' => 'WEB'])
            ->assertSessionHasErrors('key');

        // Same key in a different workspace: fine, they never collide.
        $this->actingAs($globexUser)
            ->post($this->workspaceUrl($globex, '/projects'), ['name' => 'Web', 'key' => 'WEB'])
            ->assertRedirect();
    }

    #[Test]
    public function issue_numbers_are_gapless_and_sequential(): void
    {
        [$workspace] = $this->workspaceWithMember();

        $numbers = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();

            return collect(range(1, 5))
                ->map(fn () => \Illuminate\Support\Facades\DB::transaction(
                    fn () => $project->nextIssueNumber()
                ))
                ->all();
        });

        $this->assertSame([1, 2, 3, 4, 5], $numbers);
    }

    #[Test]
    public function a_client_cannot_create_projects(): void
    {
        [$workspace, $client] = $this->workspaceWithMember(WorkspaceRole::Client, 'acme');

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/projects'), ['name' => 'Sneaky'])
            ->assertForbidden();
    }

    #[Test]
    public function a_client_only_sees_projects_they_were_granted(): void
    {
        [$workspace, $client] = $this->workspaceWithMember(WorkspaceRole::Client, 'acme');

        [$granted, $hidden] = app(Tenancy::class)->run($workspace, fn () => [
            Project::factory()->create(['name' => 'Theirs']),
            Project::factory()->create(['name' => 'Internal']),
        ]);

        $granted->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/projects/'.$granted->slug))
            ->assertOk();

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/projects/'.$hidden->slug))
            ->assertForbidden();
    }

    #[Test]
    public function every_default_status_maps_to_a_real_category(): void
    {
        foreach (\App\Models\Status::DEFAULTS as $status) {
            $this->assertInstanceOf(
                StatusCategory::class,
                StatusCategory::from($status['category']),
            );
        }
    }
}
