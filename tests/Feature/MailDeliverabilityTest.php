<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Mail\Deliverability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mail that goes nowhere has to say so.
 *
 * The failure being guarded is silence, not breakage: an install on the log driver
 * accepts every invitation, every password reset and every notification, discards
 * them, and shows exactly what a working install shows.
 */
class MailDeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    private function deliverable(): Deliverability
    {
        return app(Deliverability::class);
    }

    #[Test]
    public function the_log_driver_is_not_delivery(): void
    {
        config(['mail.default' => 'log']);

        $this->assertFalse($this->deliverable()->isConfigured());
        $this->assertNotNull($this->deliverable()->reason());
    }

    #[Test]
    public function neither_is_array_or_null(): void
    {
        foreach (['array', 'null'] as $driver) {
            config(['mail.default' => $driver]);

            $this->assertFalse(
                $this->deliverable()->isConfigured(),
                "The [{$driver}] driver discards mail.",
            );
        }
    }

    #[Test]
    public function smtp_without_a_host_is_not_delivery_either(): void
    {
        // The state a half-finished form leaves behind. It fails on every send rather
        // than at the moment it was saved, so nothing connects the two.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

        $this->assertFalse($this->deliverable()->isConfigured());

        config(['mail.mailers.smtp.host' => '   ']);

        $this->assertFalse($this->deliverable()->isConfigured(), 'Whitespace is not a host.');
    }

    #[Test]
    public function smtp_with_a_host_is(): void
    {
        // The paired positive control. Without it, a method that always returned
        // false would pass every test above.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);

        $this->assertTrue($this->deliverable()->isConfigured());
        $this->assertNull($this->deliverable()->reason());
    }

    #[Test]
    public function a_deliberate_provider_is_taken_at_its_word(): void
    {
        config(['mail.default' => 'ses']);

        $this->assertTrue($this->deliverable()->isConfigured());
    }

    #[Test]
    public function the_warning_reaches_the_screen(): void
    {
        config(['mail.default' => 'log']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mail.deliverable', false));
    }

    #[Test]
    public function a_working_install_says_nothing(): void
    {
        // A banner that is always there is a banner nobody reads.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mail', null));
    }

    #[Test]
    public function only_an_operator_is_told_they_can_fix_it(): void
    {
        config([
            'mail.default' => 'log',
            'buggie.hosted' => true,
            'buggie.operators' => ['ops@buggie.eu'],
        ]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        // A workspace owner is not an operator: they still need to know, because it
        // is their invitation that failed, but pointing them at a settings page they
        // cannot open would be worse than saying nothing.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mail.deliverable', false)
                ->where('mail.can_fix', false));

        $operator = User::factory()->create(['email' => 'ops@buggie.eu']);
        $workspace->members()->attach($operator->id, [
            'role' => WorkspaceRole::Admin->value, 'joined_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mail.can_fix', true));
    }

    #[Test]
    public function no_credentials_or_hostnames_are_shared_with_the_front_end(): void
    {
        // The banner needs to say that mail is broken, not how it is configured. A
        // client sitting in a workspace has no business learning the SMTP host.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '',
            'mail.mailers.smtp.username' => 'postmaster@secret.example',
            'mail.mailers.smtp.password' => 'hunter2',
        ]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $response = $this->actingAs($owner)->get($this->workspaceUrl($workspace, '/'));

        $response->assertOk();
        $response->assertDontSee('hunter2');
        $response->assertDontSee('postmaster@secret.example');
        $response->assertDontSee('smtp', false);
    }

    #[Test]
    public function a_guest_is_told_nothing(): void
    {
        config(['mail.default' => 'log']);

        // Signed out, on the marketing page, the state of somebody else's mail server
        // is none of a visitor's business.
        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mail', null));
    }

    #[Test]
    public function inviting_someone_does_not_claim_the_mail_was_sent(): void
    {
        Notification::fake();

        config(['mail.default' => 'log']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'someone@example.com',
                'role' => WorkspaceRole::Admin->value,
                'project_ids' => [],
            ])
            ->assertRedirect();

        $this->assertStringContainsString(
            'cannot send email',
            session('success'),
            'The flash message claimed an invitation was sent when it was not.',
        );

        // The invitation itself is still created: the link on the members page is the
        // way round this, so the record has to exist for it to be copied.
        $this->assertTrue(
            Invitation::withoutGlobalScopes()->where('email', 'someone@example.com')->exists(),
        );
    }

    #[Test]
    public function inviting_someone_on_a_working_install_says_it_was_sent(): void
    {
        Notification::fake();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/members'), [
                'email' => 'someone@example.com',
                'role' => WorkspaceRole::Admin->value,
                'project_ids' => [],
            ])
            ->assertRedirect();

        $this->assertStringContainsString('Invitation sent', session('success'));
    }
}
