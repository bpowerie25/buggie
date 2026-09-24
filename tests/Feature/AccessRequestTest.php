<?php

namespace Tests\Feature;

use App\Actions\InviteToWorkspace;
use App\Enums\AccessRequestStatus;
use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccessRequested;
use App\Notifications\WorkspaceInvitation;
use App\Support\Registration\Registration;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asking to be let in, on an install where sign-up is by invitation.
 *
 * Kennco and Globex are two client workspaces on one agency's server. Neither's
 * admins may ever see or decide the other's requests; a request for no workspace is
 * the operators' alone.
 */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Workspace $matrix;

    private Workspace $kennco;

    private User $kenncoOwner;

    private User $kenncoAdmin;

    private User $kenncoMember;

    private Workspace $globex;

    private User $globexOwner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buggie.hosted' => false,
            'buggie.registration' => null,
            'buggie.operators' => ['ops@matrix.test'],
        ]);

        app(Settings::class)->put([Registration::SETTING => 'request']);

        $this->operator = User::factory()->create(['email' => 'ops@matrix.test']);
        $this->matrix = $this->workspaceOwnedBy($this->operator, 'matrix');

        [$this->kennco, $this->kenncoOwner] = $this->workspaceWithMember(slug: 'kennco');
        $this->kenncoAdmin = $this->memberOf($this->kennco, WorkspaceRole::Admin);
        $this->kenncoMember = $this->memberOf($this->kennco, WorkspaceRole::Member);

        [$this->globex, $this->globexOwner] = $this->workspaceWithMember(slug: 'globex');
    }

    // --- getting to the form ----------------------------------------------------

    #[Test]
    public function a_workspace_sign_in_page_offers_to_ask_that_workspace(): void
    {
        $this->get($this->workspaceUrl($this->kennco, '/login'))
            ->assertRedirect(central_url('login?workspace=kennco'));

        // And a guest turned away from the workspace itself arrives the same way.
        $this->get($this->workspaceUrl($this->kennco, '/projects'))
            ->assertRedirect(central_url('login?workspace=kennco'));

        $this->get($this->centralUrl('/login?workspace=kennco'))
            ->assertInertia(fn ($page) => $page
                ->where('canRegister', false)
                ->where('requestAccessUrl', workspace_url('kennco', 'request-access')));

        $this->get($this->workspaceUrl($this->kennco, '/request-access'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/request-access')
                ->where('workspaceHost', 'kennco.'.config('buggie.host')));
    }

    #[Test]
    public function the_central_sign_in_page_asks_the_operators(): void
    {
        $this->get($this->centralUrl('/login'))
            ->assertInertia(fn ($page) => $page->where('requestAccessUrl', central_url('request-access')));

        $this->get($this->centralUrl('/login?workspace=no-such-place'))
            ->assertInertia(fn ($page) => $page->where('requestAccessUrl', central_url('request-access')));

        $this->get($this->centralUrl('/request-access'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('workspaceHost', null));
    }

    #[Test]
    public function the_form_does_not_exist_unless_the_install_takes_requests(): void
    {
        foreach (['invite', 'open'] as $mode) {
            app(Settings::class)->put([Registration::SETTING => $mode]);

            $this->get($this->workspaceUrl($this->kennco, '/request-access'))->assertNotFound();
            $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking())->assertNotFound();
            $this->get($this->centralUrl('/login?workspace=kennco'))
                ->assertInertia(fn ($page) => $page->where('requestAccessUrl', null));
        }

        $this->assertSame(0, AccessRequest::query()->acrossAllWorkspaces()->count());
    }

    // --- routing ----------------------------------------------------------------

    #[Test]
    public function a_request_goes_to_that_workspaces_owners_and_admins(): void
    {
        Notification::fake();

        $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking([
            'message' => 'I run the Kennco website.',
        ]))->assertRedirect()->assertSessionHas('access_requested', true);

        $request = AccessRequest::query()->acrossAllWorkspaces()->sole();
        $this->assertSame($this->kennco->id, $request->workspace_id);
        $this->assertSame('newcomer@kennco.test', $request->email);
        $this->assertSame(AccessRequestStatus::Pending, $request->status);

        Notification::assertSentTo([$this->kenncoOwner, $this->kenncoAdmin], AccessRequested::class,
            fn (AccessRequested $n) => $n->reviewUrl === workspace_url('kennco', 'settings/access-requests'));
        Notification::assertNotSentTo([$this->kenncoMember, $this->globexOwner, $this->operator], AccessRequested::class);
    }

    #[Test]
    public function a_request_for_a_new_workspace_goes_only_to_operators(): void
    {
        Notification::fake();

        $this->post($this->centralUrl('/request-access'), $this->asking())
            ->assertSessionHasErrors('organisation');

        $this->post($this->centralUrl('/request-access'), $this->asking(['organisation' => 'Initech']))
            ->assertSessionHas('access_requested', true);

        $request = AccessRequest::query()->acrossAllWorkspaces()->sole();
        $this->assertNull($request->workspace_id);
        $this->assertSame('Initech', $request->organisation);

        Notification::assertSentTo($this->operator, AccessRequested::class,
            fn (AccessRequested $n) => $n->reviewUrl === workspace_url('matrix', 'settings/instance#access-requests'));
        Notification::assertNotSentTo([$this->kenncoOwner, $this->kenncoAdmin, $this->globexOwner], AccessRequested::class);
    }

    #[Test]
    public function operators_are_the_fallback_for_a_workspace_nobody_can_invite_to(): void
    {
        Notification::fake();

        // Only an ordinary member left: nobody there could act on a request.
        $orphan = Workspace::factory()->create(['slug' => 'orphan', 'owner_id' => $this->kenncoMember->id]);
        $orphan->members()->attach($this->kenncoMember->id, ['role' => 'member', 'joined_at' => now()]);

        $this->post($this->workspaceUrl($orphan, '/request-access'), $this->asking());

        Notification::assertSentTo($this->operator, AccessRequested::class);
        Notification::assertNotSentTo($this->kenncoMember, AccessRequested::class);
    }

    #[Test]
    public function operators_see_every_request_on_the_instance_screen(): void
    {
        $this->requestFor($this->kennco, 'a@kennco.test');
        $this->requestFor($this->globex, 'b@globex.test');
        AccessRequest::factory()->central()->createQuietly(['email' => 'c@initech.test']);

        $this->actingAs($this->operator)
            ->get($this->workspaceUrl($this->matrix, '/settings/instance'))
            ->assertInertia(fn ($page) => $page
                ->where('accessRequests.enabled', true)
                ->has('accessRequests.requests', 3)
                ->where('accessRequests.requests', fn ($rows) => collect($rows)->pluck('workspace.slug')->sort()->values()->all()
                    === [null, 'globex', 'kennco']));
    }

    // --- deciding ---------------------------------------------------------------

    #[Test]
    public function approving_sends_the_ordinary_invitation_and_it_brings_them_in(): void
    {
        Notification::fake();
        $request = $this->requestFor($this->kennco, 'newcomer@kennco.test');

        $this->actingAs($this->kenncoAdmin)
            ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/approve"), [
                'role' => 'member',
            ])->assertSessionHasNoErrors();

        $invitation = Invitation::query()->acrossAllWorkspaces()->sole();
        $this->assertSame($this->kennco->id, $invitation->workspace_id);
        $this->assertSame('newcomer@kennco.test', $invitation->email);
        $this->assertSame(WorkspaceRole::Member, $invitation->role);
        $this->assertSame($this->kenncoAdmin->id, $invitation->invited_by_id);

        Notification::assertSentOnDemand(WorkspaceInvitation::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'newcomer@kennco.test');

        $request->refresh();
        $this->assertSame(AccessRequestStatus::Approved, $request->status);
        $this->assertSame($this->kenncoAdmin->id, $request->decided_by_id);
        $this->assertSame($invitation->id, $request->invitation_id);
        $this->assertNotNull($request->decided_at);

        // And the invitation is the ordinary one: a brand-new person registers
        // through it on an install that is otherwise closed.
        auth()->logout();
        $this->get($invitation->url())->assertRedirect(central_url('register'));
        $this->post($this->centralUrl('/register'), [
            'name' => 'New Comer',
            'email' => 'newcomer@kennco.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect($invitation->url());
        $this->post($invitation->url())->assertRedirect(workspace_url('kennco'));

        $this->assertTrue(User::where('email', 'newcomer@kennco.test')->sole()->belongsToWorkspace($this->kennco));
    }

    #[Test]
    public function approving_a_client_needs_projects_and_grants_only_those(): void
    {
        Notification::fake();
        $request = $this->requestFor($this->kennco, 'client@kennco.test');
        $project = app(Tenancy::class)->run($this->kennco, fn () => Project::factory()->create());
        $elsewhere = app(Tenancy::class)->run($this->globex, fn () => Project::factory()->create());

        $url = $this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/approve");

        $this->actingAs($this->kenncoOwner)->post($url, ['role' => 'client'])
            ->assertSessionHasErrors('project_ids');

        // Another workspace's project is not a project here.
        $this->actingAs($this->kenncoOwner)->post($url, ['role' => 'client', 'project_ids' => [$elsewhere->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->actingAs($this->kenncoOwner)->post($url, ['role' => 'client', 'project_ids' => [$project->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$project->id], Invitation::query()->acrossAllWorkspaces()->sole()->project_ids);
    }

    #[Test]
    public function an_owner_role_cannot_be_handed_out_through_a_request(): void
    {
        $request = $this->requestFor($this->kennco, 'newcomer@kennco.test');

        $this->actingAs($this->kenncoOwner)
            ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/approve"), ['role' => 'owner'])
            ->assertSessionHasErrors('role');
    }

    #[Test]
    public function declining_is_recorded_and_tells_the_requester_nothing(): void
    {
        Notification::fake();
        $request = $this->requestFor($this->kennco, 'newcomer@kennco.test');

        $this->actingAs($this->kenncoOwner)
            ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/decline"), [
                'reason' => 'Not somebody we work with.',
            ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(AccessRequestStatus::Declined, $request->status);
        $this->assertSame('Not somebody we work with.', $request->decline_reason);
        $this->assertSame($this->kenncoOwner->id, $request->decided_by_id);

        Notification::assertNothingSent();
        $this->assertSame(0, Invitation::query()->acrossAllWorkspaces()->count());
    }

    #[Test]
    public function a_request_is_decided_once(): void
    {
        Notification::fake();
        $request = $this->requestFor($this->kennco, 'newcomer@kennco.test');

        $this->actingAs($this->kenncoOwner)
            ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/decline"));

        $this->actingAs($this->kenncoAdmin)
            ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/approve"), ['role' => 'member'])
            ->assertForbidden();

        $this->assertSame(0, Invitation::query()->acrossAllWorkspaces()->count());
    }

    #[Test]
    public function an_operator_approves_a_request_for_a_new_workspace_into_one(): void
    {
        Notification::fake();
        $request = AccessRequest::factory()->central()->createQuietly(['email' => 'boss@initech.test']);

        $this->actingAs($this->operator)
            ->post($this->workspaceUrl($this->matrix, "/settings/instance/access-requests/{$request->id}/approve"), ['role' => 'admin'])
            ->assertSessionHasErrors('workspace');

        $this->actingAs($this->operator)
            ->post($this->workspaceUrl($this->matrix, "/settings/instance/access-requests/{$request->id}/approve"), [
                'role' => 'admin',
                'workspace' => 'globex',
            ])->assertSessionHasNoErrors();

        $invitation = Invitation::query()->acrossAllWorkspaces()->sole();
        $this->assertSame($this->globex->id, $invitation->workspace_id);
        $this->assertSame(WorkspaceRole::Admin, $invitation->role);
        $this->assertSame(AccessRequestStatus::Approved, $request->fresh()->status);
    }

    #[Test]
    public function an_operator_can_decide_any_workspaces_request_as_the_fallback(): void
    {
        $request = $this->requestFor($this->globex, 'someone@globex.test');

        $this->actingAs($this->operator)
            ->post($this->workspaceUrl($this->matrix, "/settings/instance/access-requests/{$request->id}/decline"))
            ->assertSessionHasNoErrors();

        $this->assertSame(AccessRequestStatus::Declined, $request->fresh()->status);
    }

    // --- tenant isolation -------------------------------------------------------

    #[Test]
    public function a_kennco_admin_never_sees_or_decides_anybody_elses_requests(): void
    {
        $ours = $this->requestFor($this->kennco, 'ours@kennco.test');
        $theirs = $this->requestFor($this->globex, 'theirs@globex.test');
        $central = AccessRequest::factory()->central()->createQuietly(['email' => 'central@initech.test']);

        $this->actingAs($this->kenncoAdmin)
            ->get($this->workspaceUrl($this->kennco, '/settings/access-requests'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/access-requests')
                ->has('requests', 1)
                ->where('requests.0.id', $ours->id))
            ->assertDontSee('theirs@globex.test')
            ->assertDontSee('central@initech.test');

        foreach ([$theirs, $central] as $request) {
            foreach (['approve', 'decline'] as $decision) {
                $this->actingAs($this->kenncoAdmin)
                    ->post($this->workspaceUrl($this->kennco, "/settings/access-requests/{$request->id}/{$decision}"), ['role' => 'member'])
                    ->assertNotFound();

                // Nor through the operators' door.
                $this->actingAs($this->kenncoAdmin)
                    ->post($this->workspaceUrl($this->kennco, "/settings/instance/access-requests/{$request->id}/{$decision}"), ['role' => 'member'])
                    ->assertForbidden();
            }

            $this->assertTrue($request->fresh()->isPending());
        }

        // The policy holds on its own, not only because the scope got there first.
        app(Tenancy::class)->run($this->kennco, function () use ($ours, $theirs, $central) {
            $this->assertTrue($this->kenncoAdmin->can('decide', $ours));
            $this->assertFalse($this->kenncoAdmin->can('decide', $theirs));
            $this->assertFalse($this->kenncoAdmin->can('decide', $central));
        });

        // Nor by standing in Globex, where they are not a member.
        $this->actingAs($this->kenncoAdmin)
            ->get($this->workspaceUrl($this->globex, '/settings/access-requests'))
            ->assertNotFound();

        // And the Instance screen, which lists everything, is not theirs.
        $this->actingAs($this->kenncoAdmin)
            ->get($this->workspaceUrl($this->kennco, '/settings/instance'))
            ->assertForbidden();
    }

    #[Test]
    public function a_member_who_cannot_invite_cannot_see_requests(): void
    {
        $this->requestFor($this->kennco, 'newcomer@kennco.test');

        $this->actingAs($this->kenncoMember)
            ->get($this->workspaceUrl($this->kennco, '/settings/access-requests'))
            ->assertForbidden();

        $this->actingAs($this->kenncoMember)
            ->get($this->workspaceUrl($this->kennco, '/'))
            ->assertInertia(fn ($page) => $page->where('accessRequests', null));
    }

    // --- abuse ------------------------------------------------------------------

    #[Test]
    public function somebody_already_in_the_workspace_is_refused_without_being_told(): void
    {
        Notification::fake();

        $response = $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking([
            'email' => strtoupper($this->kenncoMember->email),
        ]));

        // Exactly what a kept request gets.
        $response->assertRedirect()->assertSessionHas('access_requested', true)->assertSessionHasNoErrors();

        $this->assertSame(0, AccessRequest::query()->acrossAllWorkspaces()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function somebody_already_invited_is_refused_without_being_told(): void
    {
        Notification::fake();
        app(Tenancy::class)->run($this->kennco, fn () => app(InviteToWorkspace::class)
            ->handle('invited@kennco.test', WorkspaceRole::Member, [], $this->kenncoOwner));
        Notification::fake();

        $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking(['email' => 'invited@kennco.test']))
            ->assertSessionHas('access_requested', true);

        $this->assertSame(0, AccessRequest::query()->acrossAllWorkspaces()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_honeypot_is_thanked_and_ignored(): void
    {
        Notification::fake();

        $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking(['website' => 'http://spam.test']))
            ->assertSessionHas('access_requested', true);

        $this->assertSame(0, AccessRequest::query()->acrossAllWorkspaces()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function one_address_is_throttled(): void
    {
        foreach ([$this->kennco, $this->globex, $this->matrix] as $workspace) {
            $this->post($this->workspaceUrl($workspace, '/request-access'), $this->asking())
                ->assertSessionHasNoErrors();
        }

        $this->post($this->centralUrl('/request-access'), $this->asking(['organisation' => 'Initech']))
            ->assertSessionHasErrors('email');

        $this->assertSame(3, AccessRequest::query()->acrossAllWorkspaces()->count());
    }

    #[Test]
    public function one_ip_address_is_throttled(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking(['email' => "person{$i}@example.test"]))
                ->assertSessionHasNoErrors();
        }

        $this->post($this->workspaceUrl($this->kennco, '/request-access'), $this->asking(['email' => 'eleventh@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('access_requests', ['email' => 'eleventh@example.test']);
    }

    #[Test]
    public function pending_requests_per_address_are_capped(): void
    {
        // Made directly, not through the form, so the rate limit is not what stops it.
        foreach ([$this->kennco, $this->globex, $this->matrix] as $workspace) {
            $this->requestFor($workspace, 'persistent@example.test');
        }

        $fourth = Workspace::factory()->create(['slug' => 'fourth', 'owner_id' => $this->operator->id]);

        $this->post($this->workspaceUrl($fourth, '/request-access'), $this->asking(['email' => 'persistent@example.test']))
            ->assertSessionHas('access_requested', true);

        $this->assertSame(3, AccessRequest::query()->acrossAllWorkspaces()->where('email', 'persistent@example.test')->count());
    }

    #[Test]
    public function the_notification_cannot_carry_a_link_the_requester_wrote(): void
    {
        $mail = (new AccessRequested('[Reset your password](https://evil.test) <b>x</b>', 'a@b.test', 'Kennco', 'https://review.test'))
            ->toMail(new \stdClass);

        $this->assertStringNotContainsString('](', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('<b>', implode(' ', $mail->introLines));
    }

    // --- helpers ----------------------------------------------------------------

    /** @return array<string, string> */
    private function asking(array $overrides = []): array
    {
        return [
            'name' => 'New Comer',
            'email' => 'newcomer@kennco.test',
            'message' => '',
            ...$overrides,
        ];
    }

    private function requestFor(Workspace $workspace, string $email): AccessRequest
    {
        return AccessRequest::factory()->create(['workspace_id' => $workspace->id, 'email' => $email]);
    }

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

        return $user;
    }

    private function workspaceOwnedBy(User $user, string $slug): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'slug' => $slug]);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return $workspace;
    }
}
