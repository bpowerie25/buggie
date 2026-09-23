<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One level of hierarchy, and the four ways it could stop being one level.
 */
class SubtaskTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\User, 2: Project, 3: array<int, Issue>} */
    private function issues(int $count = 3): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        [$project, $issues] = app(Tenancy::class)->run($workspace, function () use ($owner, $count) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return [$project, collect(range(1, $count))
                ->map(fn (int $i) => app(CreateIssue::class)->handle($project, ['title' => "Issue {$i}"], $owner))
                ->all()];
        });

        return [$workspace, $owner, $project, $issues];
    }

    private function setParent($workspace, $user, Issue $child, ?string $parentKey)
    {
        return $this->actingAs($user)->patch(
            $this->workspaceUrl($workspace, "/issues/{$child->key}/parent"),
            ['parent' => $parentKey],
        );
    }

    #[Test]
    public function an_issue_can_be_made_a_subtask_and_freed_again(): void
    {
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        $this->assertSame($issues[0]->id, $issues[1]->fresh()->parent_id);

        app(Tenancy::class)->run($workspace, function () use ($issues) {
            $this->assertSame(1, $issues[0]->fresh()->children()->count());
        });

        $this->setParent($workspace, $owner, $issues[1], null)->assertRedirect();

        $this->assertNull($issues[1]->fresh()->parent_id);
    }

    #[Test]
    public function an_issue_cannot_be_its_own_parent(): void
    {
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[0], $issues[0]->key)
            ->assertSessionHasErrors('parent');

        $this->assertNull($issues[0]->fresh()->parent_id);
    }

    #[Test]
    public function subtasks_do_not_nest(): void
    {
        // Two levels is a hierarchy; three is a project plan, and every count on every
        // screen becomes a tree walk.
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        // Issue 3 under Issue 2, which is already a child.
        $this->setParent($workspace, $owner, $issues[2], $issues[1]->key)
            ->assertSessionHasErrors('parent');

        $this->assertNull($issues[2]->fresh()->parent_id);
    }

    #[Test]
    public function an_issue_with_children_cannot_become_one(): void
    {
        // The same rule approached from the other end, and the one an implementation
        // that only checked the parent would miss.
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        // Issue 1 now has a child. It cannot become somebody's child.
        $this->setParent($workspace, $owner, $issues[0], $issues[2]->key)
            ->assertSessionHasErrors('parent');

        $this->assertNull($issues[0]->fresh()->parent_id);
    }

    #[Test]
    public function a_subtask_stays_in_its_parents_project(): void
    {
        [$workspace, $owner, , $issues] = $this->issues();

        $elsewhere = app(Tenancy::class)->run($workspace, function () use ($owner) {
            $other = app(CreateProject::class)->handle(['name' => 'Another']);

            return app(CreateIssue::class)->handle($other, ['title' => 'Over here'], $owner);
        });

        $this->setParent($workspace, $owner, $elsewhere, $issues[0]->key)
            ->assertSessionHasErrors('parent');
    }

    #[Test]
    public function a_parent_from_another_workspace_is_refused(): void
    {
        // Keys are guessable and `exists:issues,key` is not workspace-scoped, so
        // accepting one would both corrupt the tree and confirm the key exists.
        [$acme, $owner, , $ours] = $this->issues();
        [$other, $stranger] = $this->workspaceWithMember(WorkspaceRole::Owner, 'other');

        $theirs = app(Tenancy::class)->run($other, function () use ($stranger) {
            $project = app(CreateProject::class)->handle(['name' => 'Theirs']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Not ours'], $stranger);
        });

        $this->setParent($acme, $owner, $ours[0], $theirs->key)
            ->assertSessionHasErrors('parent');

        $this->assertNull($ours[0]->fresh()->parent_id);

        // The paired control: a key in our own workspace is accepted.
        $this->setParent($acme, $owner, $ours[0], $ours[1]->key)->assertRedirect();
        $this->assertSame($ours[1]->id, $ours[0]->fresh()->parent_id);
    }

    #[Test]
    public function a_soft_deleted_parent_keeps_the_link_so_restoring_it_restores_the_tree(): void
    {
        /*
         * A soft delete is an UPDATE, so the nullOnDelete foreign key does not fire
         * and the child goes on pointing at its parent. That is the behaviour worth
         * having: the parent is in the trash, the relation reads as empty because the
         * soft-delete scope hides it, and bringing the parent back brings the
         * hierarchy back with it. Nulling the link on a soft delete would make the
         * restore a half-restore.
         */
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}"))
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($issues) {
            $child = Issue::where('key', $issues[1]->key)->first();

            $this->assertNotNull($child, 'The child went with its parent.');

            // No screen shows a phantom parent: the relation is empty while it is in
            // the trash.
            $this->assertNull($child->parent, 'A deleted parent was still readable.');
        });

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$issues[0]->key}/restore"))
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($issues) {
            $child = Issue::where('key', $issues[1]->key)->first();

            $this->assertNotNull($child->parent, 'Restoring the parent did not restore the tree.');
            $this->assertSame($issues[0]->key, $child->parent->key);
        });
    }

    #[Test]
    public function deleting_a_parent_for_good_orphans_its_children_rather_than_taking_them(): void
    {
        // Here the foreign key does fire, and nullOnDelete is why the work underneath
        // a permanently deleted parent survives it.
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/trash/{$issues[0]->key}"))
            ->assertRedirect();

        $child = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::where('key', $issues[1]->key)->first(),
        );

        $this->assertNotNull($child, 'The child went with its parent.');
        $this->assertNull($child->parent_id);
    }

    #[Test]
    public function a_client_cannot_restructure_the_tree(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = \App\Models\User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issues] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return [$project, [
                app(CreateIssue::class)->handle($project, ['title' => 'A', 'visibility' => 'client'], $staff),
                app(CreateIssue::class)->handle($project, ['title' => 'B', 'visibility' => 'client'], $staff),
            ]];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->setParent($workspace, $client, $issues[1], $issues[0]->key)->assertForbidden();

        // The control: staff can.
        $this->setParent($workspace, $staff, $issues[1], $issues[0]->key)->assertRedirect();
    }

    #[Test]
    public function subtasks_are_filterable_and_orphans_are_findable(): void
    {
        // `no:parent` is the half that gets used: a planning board starts as a list of
        // work that is not part of anything yet.
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode("parent:{$issues[0]->key}")))
            ->assertOk()
            ->assertSee('Issue 2')
            ->assertDontSee('Issue 3');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('no:parent')))
            ->assertOk()
            ->assertSee('Issue 3')
            ->assertDontSee('Issue 2');
    }

    #[Test]
    public function the_issue_page_shows_the_family(): void
    {
        [$workspace, $owner, , $issues] = $this->issues();

        $this->setParent($workspace, $owner, $issues[1], $issues[0]->key)->assertRedirect();

        // The parent lists its children.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parent', null)
                ->where('children.0.key', $issues[1]->key));

        // The child names its parent.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/issues/{$issues[1]->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parent.key', $issues[0]->key)
                ->where('children', []));
    }
}
