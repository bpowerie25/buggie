<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\IssueEventType;
use App\Enums\IssueType;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Changing an issue's type from its sidebar. Staff may; a client sees it and cannot
 * change it, and that is refused by the policy, not merely by a missing control.
 */
class IssueTypeEditingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'acme');
        $this->project = app(Tenancy::class)->run($this->workspace, fn () => Project::factory()->create(['key' => 'WEB']));
    }

    /** @return array<string, array{0: WorkspaceRole}> */
    public static function staff(): array
    {
        return [
            'owner' => [WorkspaceRole::Owner],
            'admin' => [WorkspaceRole::Admin],
            'member' => [WorkspaceRole::Member],
        ];
    }

    #[Test]
    #[DataProvider('staff')]
    public function staff_change_the_type_and_the_feed_says_so(WorkspaceRole $role): void
    {
        $issue = $this->issue();
        $actor = $role === WorkspaceRole::Owner ? $this->owner : $this->member($role);

        $this->actingAs($actor)
            ->patch($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"), ['type' => 'feature'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $issue = $this->reload($issue);
        $this->assertSame(IssueType::Feature, $issue->type);

        $event = $issue->events->firstWhere('type', IssueEventType::TypeChanged);
        $this->assertNotNull($event, 'No activity entry was recorded for the type change.');
        $this->assertEquals(['from' => 'bug', 'to' => 'feature'], $event->data); // jsonb does not keep key order
        $this->assertSame($actor->id, $event->user_id);
    }

    #[Test]
    public function a_client_is_refused_even_on_an_issue_they_can_see(): void
    {
        $client = $this->member(WorkspaceRole::Client);
        $this->project->clients()->attach($client->id, ['role' => 'client']);

        $issue = $this->issue(['visibility' => 'client'], reporter: $client);

        // They can see it: the refusal is about changing it, not reaching it.
        $this->actingAs($client)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.update', false));

        $this->actingAs($client)
            ->patch($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"), ['type' => 'feature'])
            ->assertForbidden();

        $issue = $this->reload($issue);
        $this->assertSame(IssueType::Bug, $issue->type);
        $this->assertNull($issue->events->firstWhere('type', IssueEventType::TypeChanged));
    }

    #[Test]
    public function a_type_that_does_not_exist_is_rejected(): void
    {
        $issue = $this->issue();

        foreach (['epic', 'BUG', '', 'bug; drop table issues'] as $type) {
            $this->actingAs($this->owner)
                ->patch($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"), ['type' => $type])
                ->assertSessionHasErrors('type', "Accepted [{$type}] as a type.");
        }

        $issue = $this->reload($issue);
        $this->assertSame(IssueType::Bug, $issue->type);
        $this->assertNull($issue->events->firstWhere('type', IssueEventType::TypeChanged));
    }

    #[Test]
    public function setting_the_type_it_already_has_records_nothing(): void
    {
        $issue = $this->issue();

        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"), ['type' => 'bug']);

        $this->assertNull($this->reload($issue)->events->firstWhere('type', IssueEventType::TypeChanged));
    }

    #[Test]
    public function the_sidebar_is_given_the_types_to_choose_from(): void
    {
        $issue = $this->issue();

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertInertia(fn ($page) => $page
                ->where('can.update', true)
                ->where('facets.types', IssueType::options()));
    }

    private function issue(array $attributes = [], ?User $reporter = null): Issue
    {
        return app(Tenancy::class)->run($this->workspace, fn () => app(CreateIssue::class)->handle(
            $this->project,
            ['title' => 'Checkout button does nothing', 'type' => 'bug', ...$attributes],
            $reporter ?? $this->owner,
        ));
    }

    private function reload(Issue $issue): Issue
    {
        return app(Tenancy::class)->run($this->workspace, fn () => Issue::with('events')->findOrFail($issue->id));
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

        return $user;
    }
}
