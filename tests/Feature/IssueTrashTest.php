<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issues were soft-deleted from the beginning and nothing ever read them again, so a
 * delete looked careful and behaved permanently.
 */
class IssueTrashTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Issue} */
    private function deletedIssue(): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Deleted thing'], $owner);
        });

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertRedirect();

        return [$workspace, $owner, $issue];
    }

    #[Test]
    public function a_deleted_issue_can_be_found_and_brought_back(): void
    {
        [$workspace, $owner, $issue] = $this->deletedIssue();

        $this->assertNull(
            app(Tenancy::class)->run($workspace, fn () => Issue::where('key', $issue->key)->first()),
            'It should be gone from the ordinary list.',
        );

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues/trash'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issues.0.key', $issue->key));

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$issue->key}/restore"))
            ->assertRedirect();

        $this->assertNotNull(
            app(Tenancy::class)->run($workspace, fn () => Issue::where('key', $issue->key)->first()),
            'Restoring did not bring it back.',
        );
    }

    #[Test]
    public function a_restored_issue_keeps_its_comments_and_history(): void
    {
        // The reason soft deletes were worth having. A restore that returned an empty
        // shell would be worse than none.
        [$workspace, $owner, $issue] = $this->deletedIssue();

        $before = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::onlyTrashed()->where('key', $issue->key)->first()->events()->count(),
        );

        $this->assertGreaterThan(0, $before, 'The fixture has no history to preserve.');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$issue->key}/restore"))
            ->assertRedirect();

        $this->assertSame($before, app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::where('key', $issue->key)->first()->events()->count(),
        ));
    }

    #[Test]
    public function permanent_deletion_is_a_separate_decision(): void
    {
        [$workspace, $owner, $issue] = $this->deletedIssue();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/trash/{$issue->key}"))
            ->assertRedirect();

        $this->assertSame(0, Issue::withoutGlobalScopes()->withTrashed()
            ->where('key', $issue->key)->count());
    }

    #[Test]
    public function a_live_issue_cannot_be_restored_or_force_deleted_through_the_trash(): void
    {
        // Otherwise the trash routes are a second, unconfirmed delete path — and
        // "restoring" a live issue would confirm it exists.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $live = app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Alive'], $owner);
        });

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$live->key}/restore"))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/issues/trash/{$live->key}"))
            ->assertNotFound();

        $this->assertNotNull(app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::where('key', $live->key)->first(),
        ));
    }

    // --------------------------------------------------------------------- access

    #[Test]
    public function a_client_cannot_reach_the_trash(): void
    {
        // The list of what has been deleted is itself worth protecting: it is a list
        // of what somebody wanted gone.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/trash'))
            ->assertForbidden();
    }

    #[Test]
    public function a_member_who_cannot_delete_cannot_undelete(): void
    {
        [$workspace, $owner, $issue] = $this->deletedIssue();

        $member = User::factory()->create();
        $workspace->members()->attach($member->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        $this->actingAs($member)
            ->get($this->workspaceUrl($workspace, '/issues/trash'))
            ->assertForbidden();

        $this->actingAs($member)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$issue->key}/restore"))
            ->assertForbidden();

        // The paired control: the owner, who can delete, can also undelete.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/trash/{$issue->key}/restore"))
            ->assertRedirect();
    }

    #[Test]
    public function another_workspaces_deleted_issue_is_not_in_our_trash(): void
    {
        [$acme, $owner, $ours] = $this->deletedIssue();
        [$other, $stranger] = $this->workspaceWithMember(WorkspaceRole::Owner, 'other');

        // Ours is in our own trash.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($acme, '/issues/trash'))
            ->assertOk()
            ->assertSee($ours->key);

        // And nowhere else, by listing or by key.
        $this->actingAs($stranger)
            ->get($this->workspaceUrl($other, '/issues/trash'))
            ->assertOk()
            ->assertDontSee($ours->key);

        $this->actingAs($stranger)
            ->post($this->workspaceUrl($other, "/issues/trash/{$ours->key}/restore"))
            ->assertNotFound();
    }
}
