<?php

namespace Tests\Feature;

use App\Support\Mail\InboundMail;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The project screen used to offer `bugs+token@in.buggie.test` — the placeholder
 * domain — as something to copy, on an install where nothing could receive it.
 */
class InboundReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function ready(): void
    {
        config([
            'buggie.inbound_domain' => 'in.example.com',
            'buggie.mailgun_signing_key' => 'key-whatever',
        ]);
    }

    #[Test]
    public function the_placeholder_domain_is_not_a_destination(): void
    {
        config(['buggie.inbound_domain' => 'in.buggie.test', 'buggie.mailgun_signing_key' => 'key']);

        $this->assertFalse(app(InboundMail::class)->isConfigured());
        $this->assertStringContainsString('MAIL_INBOUND_DOMAIN', app(InboundMail::class)->reason());
    }

    #[Test]
    public function a_real_domain_without_a_signing_key_is_still_not_ready(): void
    {
        // The webhook refuses everything without a key, which is correct and means a
        // correctly addressed email still vanishes.
        config(['buggie.inbound_domain' => 'in.example.com', 'buggie.mailgun_signing_key' => null]);

        $this->assertFalse(app(InboundMail::class)->isConfigured());
        $this->assertStringContainsString('MAILGUN_SIGNING_KEY', app(InboundMail::class)->reason());
    }

    #[Test]
    public function both_present_is_ready(): void
    {
        // The paired control: a check that always refuses would pass both tests above.
        $this->ready();

        $this->assertTrue(app(InboundMail::class)->isConfigured());
        $this->assertNull(app(InboundMail::class)->reason());
    }

    #[Test]
    public function the_project_screen_explains_itself_rather_than_offering_a_dud(): void
    {
        config(['buggie.inbound_domain' => 'in.buggie.test']);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']),
        );

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/edit"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->whereNot('inboundReason', null));

        // And says nothing once it would work.
        $this->ready();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/edit"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('inboundReason', null));
    }
}
