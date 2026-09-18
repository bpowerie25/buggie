<?php

namespace Tests\Feature;

use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Enums\NotificationReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asking a client a question by assigning an issue to them.
 *
 * The mechanism half-existed: a client could be assigned and would be notified, but
 * the policy still required the issue to be marked client-visible, so following the
 * notification gave them a 404.
 */
class AskingAClientTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: User, 3: Project} */
    private function agencyAndClient(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create(['name' => 'Ana Power']);
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));
        $project->clients()->attach($client->id, ['role' => 'client']);

        return [$workspace, $staff, $client, $project];
    }

    #[Test]
    public function assigning_to_a_client_lets_them_actually_open_it(): void
    {
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
            'type' => IssueType::Question,
            'title' => 'Which browser were you using?',
            'visibility' => IssueVisibility::Internal,
        ]));

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}"), [
                'assignee_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame(IssueVisibility::Client, $issue->fresh()->visibility);

        // The point of the whole thing: they can open what they were asked about.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk();
    }

    #[Test]
    public function the_visibility_change_is_recorded_rather_than_done_quietly(): void
    {
        // Who can see an issue is the most consequential thing about it here. It
        // should never change without the activity feed saying so.
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
            'visibility' => IssueVisibility::Internal,
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $client->id], $staff));

        $events = $issue->fresh()->events()->get();

        $event = $events->firstWhere('type', IssueEventType::VisibilityChanged);

        $this->assertNotNull($event, 'The visibility change was not recorded.');
        $this->assertSame('assigned to a client', $event->data['because'] ?? null);
        $this->assertSame($staff->id, $event->user_id);

        // Visible to the client, so they can see why this appeared for them.
        $this->assertFalse($event->is_internal);
    }

    #[Test]
    public function a_client_cannot_be_asked_about_a_project_they_do_not_have(): void
    {
        // Otherwise assigning would hand them a project grant by the back door.
        [$workspace, $staff, $client] = $this->agencyAndClient();

        $elsewhere = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'OTHER']));

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $elsewhere->id,
        ]));

        $this->expectException(ValidationException::class);

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $client->id], $staff));
    }

    #[Test]
    public function the_refusal_leaves_the_issue_exactly_as_it_was(): void
    {
        [$workspace, $staff, $client] = $this->agencyAndClient();

        $elsewhere = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'OTHER']));
        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $elsewhere->id,
            'visibility' => IssueVisibility::Internal,
        ]));

        try {
            app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
                ->handle($issue, ['assignee_id' => $client->id], $staff));
        } catch (ValidationException) {
            // Expected.
        }

        $fresh = $issue->fresh();

        $this->assertNull($fresh->assignee_id);
        $this->assertSame(IssueVisibility::Internal, $fresh->visibility);
        $this->assertFalse($fresh->events()->where('type', IssueEventType::VisibilityChanged)->exists());
    }

    #[Test]
    public function assigning_to_a_colleague_changes_no_visibility(): void
    {
        // The control. Without it, the first test passes for an implementation that
        // simply marks everything client-visible.
        [$workspace, $staff, , $project] = $this->agencyAndClient();

        $colleague = User::factory()->create();
        $workspace->members()->attach($colleague->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
            'visibility' => IssueVisibility::Internal,
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $colleague->id], $staff));

        $this->assertSame(IssueVisibility::Internal, $issue->fresh()->visibility);
    }

    #[Test]
    public function the_client_is_told(): void
    {
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $client->id], $staff));

        $this->assertDatabaseHas('pending_notifications', [
            'user_id' => $client->id,
            'issue_id' => $issue->id,
            'reason' => NotificationReason::Assigned->value,
        ]);
    }

    #[Test]
    public function the_client_dashboard_shows_what_is_waiting_on_them(): void
    {
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
            'title' => 'Which browser were you using?',
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $client->id], $staff));

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('waitingOnYou.0.key', $issue->key)
                ->where('waitingOnYou.0.title', 'Which browser were you using?'));
    }

    #[Test]
    public function the_dashboard_list_is_not_a_way_around_the_visibility_rules(): void
    {
        // A listing is where a leak goes unnoticed, so it is filtered in the query as
        // well as by the policy — including if an internal issue is somehow assigned.
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
            'title' => 'Internal: rewrite the billing module',
            'visibility' => IssueVisibility::Internal,
            'assignee_id' => $client->id,
        ]));

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('waitingOnYou', []));
    }

    #[Test]
    public function a_client_answering_cannot_close_the_question(): void
    {
        // They can reply. Deciding the question is answered is the team's call.
        [$workspace, $staff, $client, $project] = $this->agencyAndClient();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['assignee_id' => $client->id], $staff));

        $done = $project->statuses()->where('category', 'done')->firstOrFail();

        $this->actingAs($client)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}"), ['status_id' => $done->id])
            ->assertForbidden();
    }
}
