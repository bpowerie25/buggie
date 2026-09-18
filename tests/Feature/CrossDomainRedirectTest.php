<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Workspaces live on subdomains, so signing in, signing out, switching workspace and
 * accepting an invitation all cross an origin boundary.
 *
 * An ordinary 302 is fine for a full page load, but Inertia issues these as XHR: the
 * browser follows the redirect to the other origin, the cross-origin request is
 * refused, and nothing appears to happen. Inertia::location returns a 409 carrying
 * X-Inertia-Location, which tells the client to do a hard visit instead.
 */
class CrossDomainRedirectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signing_out_from_a_workspace_tells_inertia_to_leave_the_origin(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->post($this->workspaceUrl($workspace, '/logout'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', rtrim(central_url('/'), '/').'/');

        $this->assertGuest();
    }

    #[Test]
    public function an_ordinary_form_post_still_gets_a_plain_redirect(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        // Without the Inertia header the browser follows a 302 perfectly well.
        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/logout'))
            ->assertRedirect(central_url('/'));
    }

    #[Test]
    public function signing_in_lands_on_the_workspace_subdomain(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->withHeader('X-Inertia', 'true')
            ->post($this->centralUrl('/login'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', workspace_url($workspace->slug));
    }

    #[Test]
    public function creating_a_workspace_moves_to_its_subdomain(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->post($this->centralUrl('/workspaces'), ['name' => 'Acme Ltd', 'slug' => 'acme'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', workspace_url('acme'));
    }

    #[Test]
    public function accepting_an_invitation_moves_to_the_inviting_workspace(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $invitation = app(\App\Support\Tenancy\Tenancy::class)->run(
            $workspace,
            fn () => \App\Models\Invitation::create([
                'email' => 'newcomer@example.com',
                'role' => WorkspaceRole::Member->value,
                'invited_by_id' => $owner->id,
            ]),
        );

        $newcomer = \App\Models\User::factory()->create();

        $this->actingAs($newcomer)
            ->withHeader('X-Inertia', 'true')
            ->post($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', workspace_url($workspace->slug));
    }
}
