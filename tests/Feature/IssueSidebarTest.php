<?php

namespace Tests\Feature;

use App\Enums\RelationType;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Due dates, watching and links: all three were stored, serialised to the page, and
 * unreachable.
 */
class IssueSidebarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Issue} */
    private function scenario(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
        ]));

        return [$workspace, $staff, $issue];
    }

    #[Test]
    public function a_due_date_can_be_set_and_cleared(): void
    {
        // Clearing matters as much as setting: a due date you cannot remove is one
        // that stays wrong forever.
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}"), ['due_on' => '2026-12-01'])
            ->assertRedirect();

        $this->assertSame('2026-12-01', $issue->fresh()->due_on?->toDateString());

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}"), ['due_on' => null])
            ->assertRedirect();

        $this->assertNull($issue->fresh()->due_on);
    }

    #[Test]
    public function watching_can_be_turned_on_and_off(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/watch"))
            ->assertRedirect();

        $this->assertTrue($issue->fresh()->isWatchedBy($staff));

        $this->actingAs($staff)
            ->delete($this->workspaceUrl($workspace, "/issues/{$issue->key}/watch"))
            ->assertRedirect();

        $this->assertFalse($issue->fresh()->isWatchedBy($staff));
    }

    #[Test]
    public function a_client_can_watch_an_issue_they_can_see(): void
    {
        // Watching is authorised by `view`, not `update`: a client following their own
        // bug is not editing it, and asking to be told when it moves is not a
        // privilege.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));
        $project->clients()->attach($client->id, ['role' => 'client']);

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->clientVisible()->create([
            'project_id' => $project->id,
        ]));

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/watch"))
            ->assertRedirect();

        $this->assertTrue($issue->fresh()->isWatchedBy($client));
    }

    #[Test]
    public function a_client_cannot_watch_an_issue_they_cannot_see(): void
    {
        // The control. Watching must not be a way of confirming an issue exists.
        [$workspace, $staff, $issue] = $this->scenario();

        $stranger = User::factory()->create();
        $workspace->members()->attach($stranger->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($stranger)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/watch"))
            ->assertNotFound();
    }

    #[Test]
    public function issues_can_be_linked_and_unlinked(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $other = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::factory()->create(['project_id' => $issue->project_id]),
        );

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/relations"), [
                'key' => $other->key,
                'type' => RelationType::Blocks->value,
            ])
            ->assertRedirect();

        $this->assertSame(1, $issue->fresh()->relations()->count());

        // And the inverse is recorded, so the other issue knows it is blocked.
        $this->assertSame(1, $other->fresh()->relations()->count());

        $this->actingAs($staff)
            ->delete($this->workspaceUrl($workspace, "/issues/{$issue->key}/relations"), [
                'key' => $other->key,
                'type' => RelationType::Blocks->value,
            ])
            ->assertRedirect();

        $this->assertSame(0, $issue->fresh()->relations()->count());
    }

    #[Test]
    public function a_key_from_another_workspace_cannot_be_linked(): void
    {
        [$acme, $staff, $issue] = $this->scenario();
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $elsewhere = app(Tenancy::class)->run($globex, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'GLX'])->id,
        ]));

        $this->actingAs($staff)
            ->post($this->workspaceUrl($acme, "/issues/{$issue->key}/relations"), [
                'key' => $elsewhere->key,
                'type' => RelationType::RelatesTo->value,
            ])
            ->assertSessionHasErrors('key');

        $this->assertSame(0, $issue->fresh()->relations()->count());
    }

    #[Test]
    public function the_page_carries_what_the_controls_need(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issue.watching', false)
                ->has('relationTypes', 4));
    }
}
