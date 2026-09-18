<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_invites_a_member_and_they_join(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'New.Person@example.com',
                'role' => WorkspaceRole::Member->value,
            ])
            ->assertRedirect();

        // Addresses are normalised, so one person cannot hold two invitations.
        $invitation = Invitation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('new.person@example.com', $invitation->email);

        Notification::assertSentOnDemand(WorkspaceInvitation::class);

        $newcomer = User::factory()->create();

        $this->actingAs($newcomer)
            ->post($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(workspace_url($workspace->slug));

        $this->assertTrue($newcomer->fresh()->belongsToWorkspace($workspace));
        $this->assertSame(WorkspaceRole::Member, $newcomer->fresh()->membershipIn($workspace));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    #[Test]
    public function a_client_invitation_grants_only_the_named_projects(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        [$granted, $withheld] = app(Tenancy::class)->run($workspace, fn () => [
            Project::factory()->create(['name' => 'Portal']),
            Project::factory()->create(['name' => 'Internal']),
        ]);

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'client@example.com',
                'role' => WorkspaceRole::Client->value,
                'project_ids' => [$granted->id],
            ])
            ->assertRedirect();

        $client = User::factory()->create(['email' => 'client@example.com']);
        $invitation = Invitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect();

        $projects = $client->fresh()->projects()->pluck('projects.id');

        $this->assertTrue($projects->contains($granted->id));
        $this->assertFalse($projects->contains($withheld->id), 'Clients reach only what they were given.');
    }

    #[Test]
    public function a_client_invitation_without_projects_is_refused(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'client@example.com',
                'role' => WorkspaceRole::Client->value,
                'project_ids' => [],
            ])
            ->assertSessionHasErrors('project_ids');

        Notification::assertNothingSent();
    }

    #[Test]
    public function only_admins_can_invite(): void
    {
        Notification::fake();
        [$workspace, $member] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $this->actingAs($member)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'someone@example.com',
                'role' => WorkspaceRole::Member->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function only_the_owner_can_invite_another_owner(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $admin = User::factory()->create();
        $workspace->members()->attach($admin->id, [
            'role' => WorkspaceRole::Admin->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'usurper@example.com',
                'role' => WorkspaceRole::Owner->value,
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'cofounder@example.com',
                'role' => WorkspaceRole::Owner->value,
            ])
            ->assertRedirect();
    }

    #[Test]
    public function an_expired_or_used_invitation_cannot_be_accepted(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $expired = app(Tenancy::class)->run($workspace, fn () => Invitation::create([
            'email' => 'late@example.com',
            'role' => WorkspaceRole::Member->value,
            'invited_by_id' => $owner->id,
            'expires_at' => now()->subDay(),
        ]));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/invitations/'.$expired->token))
            ->assertStatus(410);

        $this->assertFalse($user->fresh()->belongsToWorkspace($workspace));
    }

    #[Test]
    public function an_invitation_token_from_one_workspace_does_not_open_another(): void
    {
        Notification::fake();
        [$acme, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $invitation = app(Tenancy::class)->run($acme, fn () => Invitation::create([
            'email' => 'person@example.com',
            'role' => WorkspaceRole::Member->value,
            'invited_by_id' => $owner->id,
        ]));

        $user = User::factory()->create();

        // Accepting always lands in the workspace that issued the token, whatever
        // domain it was presented on.
        $this->actingAs($user)
            ->post($this->workspaceUrl($globex, '/invitations/'.$invitation->token))
            ->assertRedirect(workspace_url($acme->slug));

        $this->assertTrue($user->fresh()->belongsToWorkspace($acme));
        $this->assertFalse($user->fresh()->belongsToWorkspace($globex));
    }

    #[Test]
    public function the_workspace_owner_cannot_be_removed(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, '/settings/members/'.$owner->id))
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->belongsToWorkspace($workspace));
    }

    #[Test]
    public function removing_a_client_takes_their_project_access_with_them(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create());
        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, '/settings/members/'.$client->id))
            ->assertRedirect();

        $this->assertFalse($client->fresh()->belongsToWorkspace($workspace));
        $this->assertSame(0, $client->fresh()->projects()->count());
    }
}
