<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deleting a project used to break the whole workspace.
 *
 * Projects soft-delete, and `issues.project_id` has `cascadeOnDelete` — a database
 * rule that only fires on a real DELETE. So the issues stayed in every list belonging
 * to a project that no longer resolved, `$issue->project` returned null, and the issue
 * list threw. One click made the tracker unusable for everybody, and projects have no
 * restore path, so there was no way back.
 */
class DeletedProjectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\User, 2: Project, 3: Issue} */
    private function projectWithIssue(): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Doomed']);

            return [$project, app(CreateIssue::class)->handle($project, ['title' => 'ORPHAN-CANARY'], $owner)];
        });

        return [$workspace, $owner, $project, $issue];
    }

    #[Test]
    public function the_issue_list_survives_a_deleted_project(): void
    {
        [$workspace, $owner, $project, $issue] = $this->projectWithIssue();

        // The control: it is there beforehand, so the absence below means something.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertSee('ORPHAN-CANARY');

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertDontSee('ORPHAN-CANARY');
    }

    #[Test]
    public function the_board_and_the_export_survive_it_too(): void
    {
        // Every surface, not only the one that happened to crash first.
        [$workspace, $owner, $project] = $this->projectWithIssue();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?layout=board'))
            ->assertOk()
            ->assertDontSee('ORPHAN-CANARY');

        $csv = $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues/export'))
            ->streamedContent();

        $this->assertStringNotContainsString('ORPHAN-CANARY', $csv);
    }

    #[Test]
    public function the_orphaned_issue_is_hidden_rather_than_destroyed(): void
    {
        // Hidden, not deleted: the row is still there, so restoring the project would
        // bring its work back rather than having quietly thrown it away.
        [$workspace, $owner, $project, $issue] = $this->projectWithIssue();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}"))
            ->assertRedirect();

        $this->assertSame(0, app(Tenancy::class)->run($workspace, fn () => Issue::count()));

        $this->assertSame(
            1,
            Issue::withoutGlobalScopes()->where('key', $issue->key)->count(),
            'The issue row was destroyed rather than hidden.',
        );
    }

    #[Test]
    public function restoring_the_project_brings_its_issues_back(): void
    {
        // The reason for a scope rather than cascading the soft delete onto every
        // issue. There is no restore screen for projects yet, but the data model must
        // not have foreclosed one.
        [$workspace, $owner, $project, $issue] = $this->projectWithIssue();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}"))
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($project) {
            Project::onlyTrashed()->whereKey($project->id)->firstOrFail()->restore();
        });

        $this->assertSame(1, app(Tenancy::class)->run($workspace, fn () => Issue::count()));

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertSee('ORPHAN-CANARY');
    }

    #[Test]
    public function the_issue_page_of_an_orphan_is_not_found_rather_than_a_crash(): void
    {
        [$workspace, $owner, $project, $issue] = $this->projectWithIssue();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertNotFound();
    }
}
