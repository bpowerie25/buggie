<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Things that must not be visible to people who should not see them.
 *
 * Most of what is asserted here is already true. That is the point: these are
 * invariants that hold today and would break quietly, in ways nothing else in the
 * suite would notice. A leak does not throw an exception — it returns 200.
 */
class ExposureTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- operations

    #[Test]
    public function horizon_is_closed_to_an_ordinary_signed_in_user(): void
    {
        // Horizon lists jobs from every workspace on the install, payloads included.
        // A workspace owner is not an operator of the service.
        config(['buggie.operators' => ['operator@buggie.eu']]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->assertFalse(Gate::forUser($owner)->allows('viewHorizon'));
    }

    #[Test]
    public function horizon_is_closed_to_everyone_when_no_operator_is_named(): void
    {
        // Empty must fail closed. An install that forgot to name an operator should
        // let nobody in, not everybody.
        config(['buggie.operators' => []]);

        [, $owner] = $this->workspaceWithMember();

        $this->assertFalse(Gate::forUser($owner)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }

    #[Test]
    public function horizon_admits_a_named_operator(): void
    {
        // The control: without it, the two tests above pass for a gate that refuses
        // everybody, including the person who needs it.
        $operator = User::factory()->create(['email' => 'operator@buggie.eu']);

        config(['buggie.operators' => ['operator@buggie.eu']]);

        $this->assertTrue(Gate::forUser($operator)->allows('viewHorizon'));
    }

    #[Test]
    public function the_widget_development_harnesses_are_not_routes_in_production(): void
    {
        // They render a deliberately broken checkout with a live password field.
        // Harmless locally, embarrassing on a public site.
        $this->assertTrue(Route::has('widget.demo') || Route::has('npm.demo'));

        $registered = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), '-demo'));

        $this->assertTrue(
            $registered,
            'Expected the demo routes to exist outside production, so that the '
            .'production guard in routes/web.php is guarding something real.',
        );

        // The guard itself, read from the source: booting a second application as
        // production inside a test is more fragile than the thing being checked.
        $this->assertStringContainsString(
            'if (! app()->isProduction()) {',
            (string) file_get_contents(base_path('routes/web.php')),
            'The demo routes must stay behind a production guard.',
        );
    }

    // ---------------------------------------------------------------- enumeration

    #[Test]
    public function the_reset_form_does_not_reveal_who_has_an_account(): void
    {
        $known = User::factory()->create(['email' => 'known@example.com']);

        $forKnown = $this->post($this->centralUrl('/forgot-password'), ['email' => $known->email]);
        $forUnknown = $this->post($this->centralUrl('/forgot-password'), ['email' => 'nobody@example.com']);

        $this->assertSame($forKnown->status(), $forUnknown->status());
        $this->assertSame(
            session('status'),
            $forUnknown->baseResponse->getSession()?->get('status'),
        );
        $forUnknown->assertSessionHasNoErrors();
    }

    #[Test]
    public function signing_in_does_not_reveal_who_has_an_account(): void
    {
        // A different message for "no such user" and "wrong password" turns the login
        // form into a way of finding out who has an account here.
        User::factory()->create(['email' => 'known@example.com', 'password' => 'correct-horse']);

        $wrongPassword = $this->post($this->centralUrl('/login'), [
            'email' => 'known@example.com',
            'password' => 'not-the-password',
        ]);

        $noSuchUser = $this->post($this->centralUrl('/login'), [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ]);

        // Compared as a whole rather than by field: a difference anywhere in the
        // response — which key carries the error, or its wording — is enough to tell
        // the two cases apart.
        $errors = fn ($response) => json_encode($response->baseResponse->getSession()->get('errors'));

        $wrongPassword->assertSessionHasErrors('email');
        $noSuchUser->assertSessionHasErrors('email');
        $this->assertSame($errors($wrongPassword), $errors($noSuchUser));
    }

    #[Test]
    public function an_unknown_workspace_is_indistinguishable_from_one_you_cannot_enter(): void
    {
        // Otherwise the difference between 404 and 403 tells an outsider which
        // companies have accounts here.
        [$workspace, $member] = $this->workspaceWithMember(slug: 'acme');
        $outsider = User::factory()->create();

        $notAMember = $this->actingAs($outsider)->get($this->workspaceUrl($workspace, '/'));
        $doesNotExist = $this->actingAs($outsider)
            ->get('http://no-such-workspace.'.config('buggie.host').'/');

        $this->assertSame($doesNotExist->status(), $notAMember->status());
        $notAMember->assertNotFound();
    }

    // ------------------------------------------------------------------ marketing

    #[Test]
    public function the_public_landing_page_carries_no_billing_internals(): void
    {
        // Plans are serialised to the page so the prices shown match the limits
        // enforced. Stripe price ids are not prices, and have no business there.
        config([
            'buggie.hosted' => true,
            'plans.plans.studio.price_id' => 'price_secret_studio',
        ]);

        $response = $this->get($this->centralUrl('/'))->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('price_secret_studio', $html);
        $this->assertStringNotContainsString('price_id', $html);
        $this->assertStringNotContainsString('sk_', $html);

        // The control: the page really is rendering plans, so the assertions above
        // are not passing on an empty page.
        $this->assertStringContainsString('Studio', $html);
    }

    #[Test]
    public function the_documentation_cannot_read_outside_its_own_directory(): void
    {
        // The slug off the URL picks which file is read.
        foreach (['../.env', '../../AGENTS', 'a/../../.env', '.env', 'README'] as $attempt) {
            $this->get($this->centralUrl('/docs/'.$attempt))->assertNotFound();
        }
    }

    // --------------------------------------------------------------------- tenants

    #[Test]
    public function a_clients_dashboard_counts_only_their_own_projects(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        [$theirs, $someoneElses] = app(Tenancy::class)->run($workspace, fn () => [
            Project::factory()->create(['key' => 'MINE', 'name' => 'Their own site']),
            Project::factory()->create(['key' => 'OTHER', 'name' => 'A different client']),
        ]);

        $theirs->clients()->attach($client->id, ['role' => 'client']);

        $html = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->getContent();

        // Even the name of another client's project is a leak: it says who else this
        // agency works for.
        $this->assertStringNotContainsString('A different client', $html);
        $this->assertStringNotContainsString('OTHER', $html);
        $this->assertStringContainsString('Their own site', $html);
    }

    #[Test]
    public function a_client_cannot_reach_an_issue_by_guessing_its_key(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        [$granted, $issue] = app(Tenancy::class)->run($workspace, function () {
            $granted = Project::factory()->create(['key' => 'MINE']);
            $other = Project::factory()->create(['key' => 'OTHER']);

            return [$granted, \App\Models\Issue::factory()->clientVisible()->create([
                'project_id' => $other->id,
            ])];
        });

        $granted->clients()->attach($client->id, ['role' => 'client']);

        // Marked client-visible, but in a project they were never granted. Both gates
        // have to hold, not either one.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertNotFound();
    }
}
