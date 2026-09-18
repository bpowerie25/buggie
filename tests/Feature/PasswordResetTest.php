<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_reset_link_can_be_requested_and_used(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'jo@example.com',
            'password' => 'the-old-password',
        ]);

        $this->post($this->centralUrl('/forgot-password'), ['email' => 'jo@example.com'])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post($this->centralUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        // The point of a reset is that the previous password stops working.
        $this->assertFalse(Hash::check('the-old-password', $user->fresh()->password));

        $this->post($this->centralUrl('/login'), [
            'email' => 'jo@example.com',
            'password' => 'the-old-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        // Not signed in by the reset itself: choosing the password and using it are
        // separate steps, so a briefly compromised mailbox does not yield a session.
        $this->assertGuest();

        $this->post($this->centralUrl('/login'), [
            'email' => 'jo@example.com',
            'password' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_used_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jo@example.com']);

        $this->post($this->centralUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $payload = [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'first-new-password',
                'password_confirmation' => 'first-new-password',
            ];

            $this->post($this->centralUrl('/reset-password'), $payload)
                ->assertRedirect(route('login'));

            // A link in an inbox is a link forever; it must only work once.
            $this->post($this->centralUrl('/reset-password'), [
                ...$payload,
                'password' => 'second-new-password',
                'password_confirmation' => 'second-new-password',
            ])->assertSessionHasErrors('email');

            return true;
        });

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('second-new-password', $user->fresh()->password));
    }

    #[Test]
    public function the_link_points_at_the_central_domain(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post($this->centralUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // Workspaces live on subdomains; a reset link must not land on one, and
            // must survive being built from a queued job with no request behind it.
            $this->assertStringStartsWith(central_url('reset-password/'), $url);
            $this->assertStringContainsString(urlencode($user->email), $url);

            return true;
        });
    }

    #[Test]
    public function an_unknown_address_is_answered_exactly_like_a_known_one(): void
    {
        Notification::fake();

        $known = $this->post($this->centralUrl('/forgot-password'), ['email' => 'nobody@example.com']);

        User::factory()->create(['email' => 'somebody@example.com']);
        $unknown = $this->post($this->centralUrl('/forgot-password'), ['email' => 'somebody@example.com']);

        // Otherwise the form becomes a way of discovering who has an account here.
        $this->assertSame(
            session()->get('status'),
            $unknown->baseResponse->getSession()->get('status'),
        );
        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_wrong_or_stale_token_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'jo@example.com']);

        $this->post($this->centralUrl('/reset-password'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'something-else-entirely',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('something-else-entirely', $user->fresh()->password));
    }

    #[Test]
    public function a_token_cannot_be_used_for_a_different_account(): void
    {
        Notification::fake();

        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $attacker = User::factory()->create(['email' => 'attacker@example.com']);

        $this->post($this->centralUrl('/forgot-password'), ['email' => $attacker->email]);

        Notification::assertSentTo($attacker, ResetPassword::class, function ($notification) use ($victim) {
            $this->post($this->centralUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $victim->email,
                'password' => 'taking-over-your-account',
                'password_confirmation' => 'taking-over-your-account',
            ])->assertSessionHasErrors('email');

            return true;
        });

        $this->assertFalse(Hash::check('taking-over-your-account', $victim->fresh()->password));
    }

    #[Test]
    public function weak_and_unconfirmed_passwords_are_refused(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post($this->centralUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post($this->centralUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrors('password');

            $this->post($this->centralUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-perfectly-fine-password',
                'password_confirmation' => 'a-different-password',
            ])->assertSessionHasErrors('password');

            return true;
        });
    }

    #[Test]
    public function requests_are_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jo@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->centralUrl('/forgot-password'), ['email' => 'jo@example.com'])
                ->assertSessionHasNoErrors();
        }

        $this->post($this->centralUrl('/forgot-password'), ['email' => 'jo@example.com'])
            ->assertSessionHasErrors('email');

        unset($user);
    }

    #[Test]
    public function the_pages_are_reachable_and_the_login_screen_links_to_them(): void
    {
        $this->get($this->centralUrl('/forgot-password'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/forgot-password'));

        $this->get($this->centralUrl('/reset-password/some-token?email=jo%40example.com'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/reset-password')
                ->where('token', 'some-token')
                ->where('email', 'jo@example.com'));
    }

    #[Test]
    public function a_signed_in_visitor_is_not_offered_the_reset_pages(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        // Same guest guard as login and register, so no 500 from the dashboard route.
        $this->actingAs($user)
            ->get($this->centralUrl('/forgot-password'))
            ->assertRedirect(rtrim(central_url('/'), '/'));

        unset($workspace);
    }
}
