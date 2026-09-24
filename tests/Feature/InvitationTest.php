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

    #[Test]
    public function a_new_client_who_registers_lands_back_on_the_invitation(): void
    {
        // The whole journey as a real invited client walks it. Before this, they were
        // dropped on "create a workspace" — most would make a stray workspace of their
        // own and never find the one they were invited to.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $invitation = $this->inviteTo($workspace, $owner, 'client@shopper.test');

        // 1. They follow the link from the email while signed out.
        $this->get($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(central_url('register'));

        // 2. They sign up.
        $this->post($this->centralUrl('/register'), [
            'name' => 'Ana Power',
            'email' => 'client@shopper.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect($this->workspaceUrl($workspace, '/invitations/'.$invitation->token));

        // 3. And accepting now works, because they are back where they started.
        $this->post($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(workspace_url($workspace->slug));

        $this->assertTrue(
            User::where('email', 'client@shopper.test')->firstOrFail()->belongsToWorkspace($workspace),
        );
    }

    #[Test]
    public function someone_who_already_has_an_account_is_sent_to_sign_in(): void
    {
        // Registration would reject their email as taken, which reads as the
        // invitation being broken rather than as them already being known.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $existing = User::factory()->create(['email' => 'known@shopper.test']);

        $invitation = $this->inviteTo($workspace, $owner, $existing->email);

        $this->get($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(central_url('login'));

        $this->post($this->centralUrl('/login'), [
            'email' => $existing->email,
            'password' => 'password',
        ])->assertRedirect($this->workspaceUrl($workspace, '/invitations/'.$invitation->token));
    }

    #[Test]
    public function a_revoked_invitation_does_not_strand_a_new_user(): void
    {
        // The invitation can be withdrawn between them leaving and coming back. That
        // should drop them somewhere sensible, not 404 them on their first page.
        config(['buggie.registration' => 'open']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $invitation = $this->inviteTo($workspace, $owner, 'client@shopper.test');

        $this->get($this->workspaceUrl($workspace, '/invitations/'.$invitation->token));

        $invitation->delete();

        $this->post($this->centralUrl('/register'), [
            'name' => 'Ana Power',
            'email' => 'client@shopper.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect(route('workspaces.create'));
    }

    #[Test]
    public function a_revoked_invitation_on_an_invite_only_install_explains_itself(): void
    {
        // The invitation was their only way in, so without it registration is closed
        // to them — with a page that says so, rather than a 404.
        config(['buggie.hosted' => false, 'buggie.registration' => null]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $invitation = $this->inviteTo($workspace, $owner, 'client@shopper.test');

        $this->get($this->workspaceUrl($workspace, '/invitations/'.$invitation->token));

        $invitation->delete();

        $this->post($this->centralUrl('/register'), [
            'name' => 'Ana Power',
            'email' => 'client@shopper.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('auth/registration-closed'));

        $this->assertDatabaseMissing('users', ['email' => 'client@shopper.test']);
    }

    /**
     * Invited through the action, so tenancy and the deliberately not-fillable
     * workspace_id behave, and with a project granted, because a client invitation
     * without one is refused — building the journey on a state that cannot exist
     * would be testing nothing.
     */
    private function inviteTo(\App\Models\Workspace $workspace, User $owner, string $email): Invitation
    {
        return app(Tenancy::class)->run($workspace, function () use ($email, $owner) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Marketing Site']);

            return app(\App\Actions\InviteToWorkspace::class)
                ->handle($email, WorkspaceRole::Client, [$project->id], $owner);
        });
    }
}
