<?php

namespace Tests\Feature;

use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_central_domain_binds_no_workspace(): void
    {
        $this->get($this->centralUrl('/'))->assertOk();

        $this->assertFalse(app(Tenancy::class)->check());
    }

    #[Test]
    public function a_known_subdomain_resolves_its_workspace(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('workspace.name', $workspace->name));
    }

    #[Test]
    public function an_unknown_subdomain_is_a_404(): void
    {
        $this->get('http://nope.'.config('buggie.host').'/')->assertNotFound();
    }

    #[Test]
    public function reserved_subdomains_cannot_be_registered(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->post($this->centralUrl('/workspaces'), [
                'name' => 'Admin Co',
                'slug' => 'admin',
            ])
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function workspace_slugs_must_be_subdomain_safe(): void
    {
        $user = \App\Models\User::factory()->create();

        foreach (['Has Spaces', '-leading', 'trailing-', 'dots.here', 'a', 'under_score'] as $slug) {
            $this->actingAs($user)
                ->post($this->centralUrl('/workspaces'), ['name' => 'Co', 'slug' => $slug])
                ->assertSessionHasErrors('slug', "Accepted invalid slug [{$slug}].");
        }
    }

    #[Test]
    public function slugs_are_normalised_to_lowercase_rather_than_rejected(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->post($this->centralUrl('/workspaces'), [
            'name' => 'Acme Ltd',
            'slug' => 'ACME',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workspaces', ['slug' => 'acme']);
    }

    #[Test]
    public function signing_up_creates_a_workspace_and_lands_the_owner_in_it(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->post($this->centralUrl('/workspaces'), [
            'name' => 'Acme Ltd',
            'slug' => 'acme',
        ]);

        $response->assertRedirect('http://acme.'.config('buggie.host').'/');

        $this->assertDatabaseHas('workspaces', ['slug' => 'acme', 'owner_id' => $user->id]);
        $this->assertDatabaseHas('workspace_user', [
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    #[Test]
    public function the_workspace_switcher_receives_its_list_on_a_normal_visit(): void
    {
        [$acme, $user] = $this->workspaceWithMember(slug: 'acme');

        // A second workspace the same user belongs to, so the switcher has something
        // to switch to.
        [$globex] = $this->workspaceWithMember(slug: 'globex');
        $globex->members()->attach($user->id, [
            'role' => \App\Enums\WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        // Shared closure props are resolved on every visit, not withheld until a
        // partial reload — the sidebar reads workspaces.length unconditionally.
        $response = $this->actingAs($user)
            ->get($this->workspaceUrl($acme, '/'))
            ->assertInertia(fn ($page) => $page->has('workspaces', 2));

        // Ordered by name, which the factory randomises, so assert on the set.
        $urls = collect($response->viewData('page')['props']['workspaces'])->pluck('url');

        $this->assertEqualsCanonicalizing([
            'http://acme.'.config('buggie.host').'/',
            'http://globex.'.config('buggie.host').'/',
        ], $urls->all());
    }

    #[Test]
    public function an_authenticated_visitor_to_login_is_sent_onwards_not_broken(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        // Laravel's guest middleware looks for a route named 'dashboard'. Ours lives
        // on the {workspace} subdomain, so generating it from the central domain
        // throws for a missing parameter — a 500 on a page people reload out of habit.
        foreach (['/login', '/register'] as $path) {
            $this->actingAs($user)
                ->get($this->centralUrl($path))
                ->assertRedirect(rtrim(central_url('/'), '/'));
        }

        // And the central home knows where they actually belong.
        $this->actingAs($user)
            ->get($this->centralUrl('/'))
            ->assertRedirect(workspace_url($workspace->slug));
    }

    #[Test]
    public function a_guest_cannot_reach_a_workspace(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->get($this->workspaceUrl($workspace, '/projects'))
            ->assertRedirect();
    }
}
