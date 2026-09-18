<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Project;
use App\Models\Status;
use App\Models\Workspace;
use App\Support\Tenancy\MissingWorkspaceContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tests that matter most in a multi-tenant product: one customer must never
 * be able to see, reach or count another's rows.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_table_with_a_workspace_id_has_a_model_using_the_trait(): void
    {
        $scoped = collect($this->tenantModels())
            ->mapWithKeys(fn (string $model) => [
                (new $model)->getTable() => in_array(
                    BelongsToWorkspace::class,
                    class_uses_recursive($model),
                    true,
                ),
            ]);

        $tables = collect(DB::select(<<<'SQL'
            SELECT table_name FROM information_schema.columns
            WHERE table_schema = 'public' AND column_name = 'workspace_id'
        SQL))->pluck('table_name');

        foreach ($tables as $table) {
            // Pivot tables carry no model; they are reached through a scoped parent.
            if (in_array($table, ['workspace_user', 'project_user'], true)) {
                continue;
            }

            $this->assertTrue(
                $scoped->get($table, false),
                "Table [{$table}] has a workspace_id but no model using BelongsToWorkspace. "
                .'Add the trait, or add the table to the pivot exclusion list above.',
            );
        }
    }

    #[Test]
    public function queries_are_filtered_to_the_current_workspace(): void
    {
        [$acme] = $this->workspaceWithMember();
        [$globex] = $this->workspaceWithMember();

        Project::factory()->count(3)->create(['workspace_id' => $acme->id]);
        Project::factory()->count(5)->create(['workspace_id' => $globex->id]);

        $tenancy = app(Tenancy::class);

        $this->assertSame(3, $tenancy->run($acme, fn () => Project::count()));
        $this->assertSame(5, $tenancy->run($globex, fn () => Project::count()));
    }

    #[Test]
    public function a_project_from_another_workspace_is_not_findable_by_id(): void
    {
        [$acme] = $this->workspaceWithMember();
        [$globex] = $this->workspaceWithMember();

        $secret = Project::factory()->create(['workspace_id' => $globex->id]);

        $found = app(Tenancy::class)->run($acme, fn () => Project::find($secret->id));

        $this->assertNull($found, 'A sibling workspace reached another tenant by primary key.');
    }

    #[Test]
    public function creating_a_model_stamps_the_current_workspace(): void
    {
        [$acme] = $this->workspaceWithMember();

        $project = app(Tenancy::class)->run($acme, fn () => Project::create([
            'name' => 'Marketing Site',
            'key' => 'MS',
            'slug' => 'marketing-site',
        ]));

        $this->assertSame($acme->id, $project->workspace_id);
    }

    #[Test]
    public function querying_a_tenant_model_in_strict_mode_without_a_workspace_throws(): void
    {
        $tenancy = app(Tenancy::class);
        $tenancy->strict();

        $this->expectException(MissingWorkspaceContext::class);

        Project::count();
    }

    #[Test]
    public function the_scope_can_be_dropped_deliberately(): void
    {
        [$acme] = $this->workspaceWithMember();
        [$globex] = $this->workspaceWithMember();

        Project::factory()->create(['workspace_id' => $acme->id]);
        Project::factory()->create(['workspace_id' => $globex->id]);

        $total = app(Tenancy::class)->run(
            $acme,
            fn () => Project::query()->acrossAllWorkspaces()->count(),
        );

        $this->assertSame(2, $total);
    }

    #[Test]
    public function a_member_of_one_workspace_gets_a_404_on_another(): void
    {
        [, $acmeUser] = $this->workspaceWithMember(WorkspaceRole::Owner, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Owner, 'globex');

        $this->actingAs($acmeUser)
            ->get($this->workspaceUrl($globex, '/projects'))
            // 404 rather than 403: a 403 would confirm the workspace exists.
            ->assertNotFound();
    }

    #[Test]
    public function a_client_cannot_see_another_workspaces_projects_in_a_listing(): void
    {
        [$acme, $acmeUser] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Owner, 'globex');

        Project::factory()->create(['workspace_id' => $acme->id, 'name' => 'Ours']);
        Project::factory()->create(['workspace_id' => $globex->id, 'name' => 'Theirs']);

        $this->actingAs($acmeUser)
            ->get($this->workspaceUrl($acme, '/projects'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Ours'));
    }

    /** @return array<int, class-string> */
    private function tenantModels(): array
    {
        return [
            \App\Models\Attachment::class,
            \App\Models\Comment::class,
            \App\Models\Issue::class,
            \App\Models\IssueEvent::class,
            \App\Models\IssueRelation::class,
            \App\Models\Label::class,
            \App\Models\SavedView::class,
            Project::class,
            \App\Models\Report::class,
            Status::class,
            \App\Models\WidgetKey::class,
        ];
    }

    #[Test]
    public function the_workspace_model_itself_is_not_workspace_scoped(): void
    {
        // It is the thing being scoped to; scoping it would be circular.
        $this->assertNotContains(
            BelongsToWorkspace::class,
            class_uses_recursive(Workspace::class),
        );
    }
}
