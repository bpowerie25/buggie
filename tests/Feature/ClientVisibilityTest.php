<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The client visibility plane. A client seeing an internal note is the failure this
 * whole design is arranged against, so it is tested from the outside, over HTTP.
 */
class ClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: User, 3: Project, 4: Issue} */
    private function scenario(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create(['name' => 'Client Person']);
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->clientVisible()->create([
                'project_id' => $project->id,
            ])];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        return [$workspace, $staff, $client, $project, $issue];
    }

    #[Test]
    public function a_client_never_receives_internal_comments(): void
    {
        [$workspace, $staff, $client, , $issue] = $this->scenario();

        app(Tenancy::class)->run($workspace, function () use ($issue, $staff) {
            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Dave broke the migration again'),
                'is_internal' => true,
            ], $staff);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('We are looking into this now'),
                'is_internal' => false,
            ], $staff);
        });

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues/'.$issue->key))
            ->assertInertia(fn ($page) => $page->has('comments', 2));

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/'.$issue->key))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('comments', 1)
                ->where('comments.0.is_internal', false))
            // Belt and braces: the internal text must not appear anywhere in the payload.
            ->assertDontSee('Dave broke the migration', false);
    }

    #[Test]
    public function a_client_cannot_post_an_internal_note(): void
    {
        [$workspace, , $client, , $issue] = $this->scenario();

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/issues/'.$issue->key.'/comments'), [
                'body' => $this->doc('Sneaking this in'),
                'is_internal' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('comments', 0);
    }

    #[Test]
    public function a_clients_comment_is_public_even_if_the_form_says_otherwise(): void
    {
        [$workspace, , $client, , $issue] = $this->scenario();

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/issues/'.$issue->key.'/comments'), [
                'body' => $this->doc('Still broken for us'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'user_id' => $client->id,
            'is_internal' => false,
        ]);
    }

    #[Test]
    public function an_internal_issue_is_invisible_to_a_client(): void
    {
        [$workspace, , $client, $project] = $this->scenario();

        $internal = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::factory()->create([
                'project_id' => $project->id,
                'visibility' => IssueVisibility::Internal->value,
            ]),
        );

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/'.$internal->key))
            ->assertForbidden();

        // And it is absent from the list, not merely hidden in the UI.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertInertia(fn ($page) => $page->has('issues', 1));
    }

    #[Test]
    public function a_client_visible_issue_in_another_project_is_still_hidden(): void
    {
        [$workspace, , $client] = $this->scenario();

        // Client-visible, but in a project this client was never granted.
        $other = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::factory()->clientVisible()->create(),
        );

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/'.$other->key))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_change_an_issues_state(): void
    {
        [$workspace, , $client, $project, $issue] = $this->scenario();

        $done = $project->statuses()->where('category', 'done')->first();

        $this->actingAs($client)
            ->patch($this->workspaceUrl($workspace, '/issues/'.$issue->key), [
                'status_id' => $done->id,
            ])
            ->assertForbidden();

        $this->assertSame($issue->status_id, $issue->fresh()->status_id);
    }

    #[Test]
    public function an_issue_a_client_files_stays_visible_to_them(): void
    {
        [$workspace, , $client, $project] = $this->scenario();

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/issues'), [
                'project_id' => $project->id,
                'title' => 'The export button is broken',
                // Even though the payload asks for internal, which would hide it
                // from the person who just reported it.
                'visibility' => IssueVisibility::Internal->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'title' => 'The export button is broken',
            'visibility' => IssueVisibility::Client->value,
            'reporter_id' => $client->id,
        ]);
    }

    #[Test]
    public function internal_activity_events_are_withheld_from_clients(): void
    {
        [$workspace, $staff, $client, $project, $issue] = $this->scenario();

        $done = $project->statuses()->where('category', 'done')->first();

        $this->actingAs($staff)->patch(
            $this->workspaceUrl($workspace, '/issues/'.$issue->key),
            ['status_id' => $done->id],
        );

        $staffEvents = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues/'.$issue->key))
            ->viewData('page')['props']['events'];

        $clientEvents = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/'.$issue->key))
            ->viewData('page')['props']['events'];

        $this->assertGreaterThan(0, count($staffEvents));
        $this->assertCount(0, $clientEvents, 'Events default to internal.');
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }
}
