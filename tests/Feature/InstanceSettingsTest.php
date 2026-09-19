<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_operator_configures_mail_without_touching_env(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.operators' => [$owner->email]]);

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, '/settings/instance'), [
                'mailer' => 'smtp',
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'username' => 'postmaster@buggie.eu',
                'password' => 'hunter2',
                'encryption' => 'tls',
                'from_address' => 'buggie@buggie.eu',
                'from_name' => 'Buggie',
            ])
            ->assertRedirect();

        $settings = app(Settings::class);

        $this->assertSame('smtp.mailgun.org', $settings->get('mail.host'));
        $this->assertSame('hunter2', $settings->get('mail.password'));
    }

    #[Test]
    public function the_password_is_not_stored_in_the_clear(): void
    {
        // A stolen database dump should not be a stolen mailbox.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.operators' => [$owner->email]]);

        $this->actingAs($owner)->patch($this->workspaceUrl($workspace, '/settings/instance'), [
            'mailer' => 'smtp',
            'host' => 'smtp.mailgun.org',
            'port' => 587,
            'password' => 'hunter2',
            'from_address' => 'buggie@buggie.eu',
            'from_name' => 'Buggie',
        ])->assertRedirect();

        $stored = DB::table('app_settings')->where('key', 'mail.password')->value('value');

        $this->assertStringNotContainsString('hunter2', (string) $stored);
        $this->assertSame('hunter2', app(Settings::class)->get('mail.password'));
    }

    #[Test]
    public function a_blank_password_keeps_the_stored_one(): void
    {
        // The form never sends the current password back, so an empty field is the
        // normal case when editing anything else on the page. Treating it as "clear
        // it" would break mail every time somebody changed the from-name.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.operators' => [$owner->email]]);

        app(Settings::class)->put(['mail.password' => 'hunter2']);

        $this->actingAs($owner)->patch($this->workspaceUrl($workspace, '/settings/instance'), [
            'mailer' => 'smtp',
            'host' => 'smtp.mailgun.org',
            'port' => 587,
            'password' => '',
            'from_address' => 'buggie@buggie.eu',
            'from_name' => 'Still Buggie',
        ])->assertRedirect();

        $this->assertSame('hunter2', app(Settings::class)->get('mail.password'));
    }

    #[Test]
    public function the_password_is_never_sent_to_the_browser(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.operators' => [$owner->email]]);

        app(Settings::class)->put(['mail.mailer' => 'smtp', 'mail.password' => 'hunter2']);

        $response = $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertOk();

        $this->assertStringNotContainsString('hunter2', $response->getContent());

        $response->assertInertia(fn ($page) => $page->where('mail.has_password', true));
    }

    #[Test]
    public function a_workspace_owner_who_is_not_an_operator_is_refused(): void
    {
        // One customer must not be able to redirect everybody else's mail.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.hosted' => true, 'buggie.operators' => ['someone.else@buggie.eu']]);

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, '/settings/instance'), [
                'mailer' => 'log',
                'from_address' => 'evil@example.com',
                'from_name' => 'Evil',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_self_hosted_install_lets_its_first_account_operate_it(): void
    {
        // It is somebody's own server. Making them edit .env before they can
        // configure mail is exactly the friction this removes.
        config(['buggie.hosted' => false, 'buggie.operators' => []]);

        [$workspace, $first] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($first)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertOk();
    }

    #[Test]
    public function a_later_account_on_a_self_hosted_install_cannot(): void
    {
        // The control: "first account" has to mean something.
        config(['buggie.hosted' => false, 'buggie.operators' => []]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $later = User::factory()->create();
        $workspace->members()->attach($later->id, [
            'role' => WorkspaceRole::Owner->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($later)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertForbidden();
    }

    #[Test]
    public function the_hosted_service_admits_nobody_when_no_operator_is_named(): void
    {
        // Fails closed, like Horizon.
        config(['buggie.hosted' => true, 'buggie.operators' => []]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertForbidden();
    }

    #[Test]
    public function the_test_message_goes_to_whoever_asked_for_it(): void
    {
        Mail::fake();

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        config(['buggie.operators' => [$owner->email]]);

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/instance/test-mail'))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Mail::raw does not register as a Mailable, so the meaningful assertion is
        // that it did not throw and the operator was told where it went.
        $this->assertStringContainsString($owner->email, (string) session('success'));
    }

    #[Test]
    public function saved_settings_actually_reach_the_mailer(): void
    {
        // Otherwise the form is a diary: it records what you wanted and changes
        // nothing.
        app(Settings::class)->put([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.test',
            'mail.port' => 2525,
            'mail.from_address' => 'noreply@buggie.eu',
            'mail.from_name' => 'Buggie',
        ]);

        // Applied directly rather than by rebooting: refreshApplication() opens a
        // new database connection, which cannot see RefreshDatabase's uncommitted
        // transaction, so the settings would not be there to read.
        app(\App\Support\Settings\MailConfiguration::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('noreply@buggie.eu', config('mail.from.address'));
    }
}
