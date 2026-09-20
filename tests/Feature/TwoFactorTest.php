<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TwoFactor\RecoveryCodes;
use App\Support\TwoFactor\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two-factor authentication, end to end.
 *
 * Written with the negatives paired: every "this is refused" has a "and this is
 * accepted" beside it, because a verifier that returns false for everything passes
 * the first half of that pair perfectly and locks every customer out.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Enrolment
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function starting_enrolment_shows_a_secret_and_switches_nothing_on(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('enabled', false)
                ->has('pending.qr')
                ->has('pending.secret'));

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    #[Test]
    public function an_unconfirmed_secret_does_not_stand_between_anybody_and_their_account(): void
    {
        // The reason enrolment is two steps at all. A scan that went to the wrong
        // app, or never happened, must be an unfinished setup and not a locked door.
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertRedirect();

        $this->signOut();

        $this->post($this->centralUrl('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(workspace_url($workspace->slug));

        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function the_wrong_code_does_not_confirm_it_and_the_right_one_does(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertRedirect();

        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor/confirm'), [
                'code' => $this->wrongCode($secret),
            ])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        // The control. Without it, "the wrong code is refused" is also true of an
        // implementation that refuses the right one.
        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor/confirm'), [
                'code' => $this->codeFor($secret),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('two_factor_recovery_codes');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function confirming_hands_over_eight_recovery_codes_once(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)->post($this->workspaceUrl($workspace, '/settings/two-factor'));

        $secret = $user->fresh()->two_factor_secret;

        $codes = $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor/confirm'), [
                'code' => $this->codeFor($secret),
            ])
            ->assertRedirect()
            ->baseResponse->getSession()->get('two_factor_recovery_codes');

        $this->assertCount(RecoveryCodes::COUNT, $codes);
        $this->assertSame(RecoveryCodes::COUNT, $user->fresh()->recoveryCodesRemaining());

        // The page that the redirect lands on shows them…
        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertInertia(fn ($page) => $page->where('recovery_codes', $codes));

        // …and a reload does not, because only hashes were kept. Somebody who closes
        // the tab has lost them, which is the honest position and is said on screen.
        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertInertia(fn ($page) => $page->where('recovery_codes', null));
    }

    #[Test]
    public function the_secret_is_encrypted_at_rest(): void
    {
        // A stolen database dump must not be a stolen second factor.
        [$user, $secret] = $this->withTwoFactor();

        $stored = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
    }

    #[Test]
    public function the_secret_stops_being_sent_to_the_browser_once_it_is_confirmed(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');
        [$enrolled, $secret] = $this->withTwoFactor($user);

        $response = $this->actingAs($enrolled)
            ->get($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page->where('enabled', true)->where('pending', null));

        // Belt and braces: not anywhere in the payload, under any key.
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    #[Test]
    public function recovery_codes_are_stored_hashed_rather_than_encrypted(): void
    {
        [$user, , $codes] = $this->withTwoFactor();

        $stored = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        foreach ($codes as $code) {
            $this->assertStringNotContainsString(RecoveryCodes::normalise($code), $stored);
        }

        // The control: they are genuinely there, as the hashes of those codes.
        $this->assertStringContainsString(RecoveryCodes::hash($codes[0]), $stored);
    }

    /*
    |--------------------------------------------------------------------------
    | The challenge at sign-in
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_right_password_alone_is_not_a_session(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        [$user] = $this->withTwoFactor();
        $this->join($workspace, $user);

        $this->post($this->centralUrl('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(central_url('/two-factor'));

        $this->assertGuest();

        // And the account is still out of reach while the challenge is outstanding.
        $this->get($this->workspaceUrl($workspace, '/'))->assertRedirect();
    }

    #[Test]
    public function a_wrong_code_is_refused_and_the_right_one_signs_you_in(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        [$user, $secret] = $this->withTwoFactor();
        $this->join($workspace, $user);

        $this->signInWithPassword($user);

        $this->post($this->centralUrl('/two-factor'), ['code' => $this->wrongCode($secret)])
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret)])
            ->assertRedirect(workspace_url($workspace->slug));

        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function a_recovery_code_gets_you_in_and_is_then_spent(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        [$user, , $codes] = $this->withTwoFactor();
        $this->join($workspace, $user);

        $this->signInWithPassword($user);
        $this->post($this->centralUrl('/two-factor'), ['code' => $codes[0]])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(RecoveryCodes::COUNT - 1, $user->fresh()->recoveryCodesRemaining());

        $this->post($this->workspaceUrl($workspace, '/logout'));
        $this->flushSession();

        // The same code again is worth nothing.
        $this->signInWithPassword($user);
        $this->post($this->centralUrl('/two-factor'), ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // The control: a different one still works, so the first was consumed rather
        // than the whole set being broken.
        $this->post($this->centralUrl('/two-factor'), ['code' => $codes[1]])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(RecoveryCodes::COUNT - 2, $user->fresh()->recoveryCodesRemaining());
    }

    #[Test]
    public function a_code_cannot_be_used_a_second_time_inside_its_own_window(): void
    {
        // Verifying is not enough on its own: the same six digits stay correct for
        // ninety seconds, so a code read over a shoulder is a sign-in unless
        // something remembers that it has been spent.
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        [$user, $secret] = $this->withTwoFactor();
        $this->join($workspace, $user);

        $code = $this->codeFor($secret);

        $this->signInWithPassword($user);
        $this->post($this->centralUrl('/two-factor'), ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());

        $this->post($this->workspaceUrl($workspace, '/logout'));
        $this->flushSession();

        $this->signInWithPassword($user);
        $this->post($this->centralUrl('/two-factor'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // The control: the next step's code is accepted, so it is this code that is
        // spent and not the account that is broken.
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret, 1)])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function one_step_of_drift_is_forgiven_and_two_is_not(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        // Separate accounts: accepting the first code moves the replay guard past
        // the earlier step, which would refuse the second for the wrong reason.
        [$early, $earlySecret] = $this->withTwoFactor();
        [$late, $lateSecret] = $this->withTwoFactor();
        $this->join($workspace, $early);
        $this->join($workspace, $late);

        $this->signInWithPassword($early);
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($earlySecret, -1)])
            ->assertRedirect();
        $this->assertAuthenticatedAs($early->fresh());

        $this->post($this->workspaceUrl($workspace, '/logout'));
        $this->flushSession();

        $this->signInWithPassword($late);
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($lateSecret, -2)])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function the_challenge_is_throttled(): void
    {
        // Six digits is a million possibilities, which is not many when the guessing
        // is free.
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        [$user, $secret] = $this->withTwoFactor();
        $this->join($workspace, $user);

        $this->signInWithPassword($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->centralUrl('/two-factor'), ['code' => $this->wrongCode($secret)])
                ->assertSessionHasErrors('code');
        }

        // The assertion that matters: a *correct* code is now refused too. Anything
        // less only proves that wrong codes are wrong.
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret)])
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        // And it is a pause rather than a wall, or a lost phone plus a typo would be
        // a support ticket.
        $this->travel(61)->seconds();

        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret)])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function the_challenge_page_is_nothing_without_a_password_first(): void
    {
        $this->get($this->centralUrl('/two-factor'))->assertRedirect(central_url('/login'));

        [$user, $secret] = $this->withTwoFactor();

        // Posting a perfectly good code with no pending sign-in gets nobody in.
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret)])
            ->assertRedirect(central_url('/login'));

        $this->assertGuest();

        // The control: the same code works once the password has been given.
        $this->signInWithPassword($user);
        $this->post($this->centralUrl('/two-factor'), ['code' => $this->codeFor($secret)])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function an_account_without_a_second_factor_signs_in_exactly_as_before(): void
    {
        // The control for the whole feature: nothing above is achieved by breaking
        // sign-in for everybody else.
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->post($this->centralUrl('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(workspace_url($workspace->slug));

        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function a_wrong_password_still_never_reaches_the_challenge(): void
    {
        [$user] = $this->withTwoFactor();

        $this->post($this->centralUrl('/login'), [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->post($this->centralUrl('/two-factor'), ['code' => '000000'])
            ->assertRedirect(central_url('/login'));

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Living with it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function turning_it_off_needs_a_code_or_a_password(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');
        [$enrolled, $secret] = $this->withTwoFactor($user);

        $this->actingAs($enrolled)
            ->delete($this->workspaceUrl($workspace, '/settings/two-factor'), [
                'code' => $this->wrongCode($secret),
                'password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('code');

        $this->assertTrue($enrolled->fresh()->hasTwoFactorEnabled());

        // Nothing at all is not proof either — otherwise an unlocked screen turns it
        // off with one click, which is the case it exists for.
        $this->actingAs($enrolled)
            ->delete($this->workspaceUrl($workspace, '/settings/two-factor'))
            ->assertSessionHasErrors('code');

        $this->assertTrue($enrolled->fresh()->hasTwoFactorEnabled());

        $this->actingAs($enrolled)
            ->delete($this->workspaceUrl($workspace, '/settings/two-factor'), [
                'code' => $this->codeFor($secret),
            ])
            ->assertSessionHasNoErrors();

        $fresh = $enrolled->fresh();

        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);

        // And sign-in goes back to one step.
        $this->signOut();
        $this->post($this->centralUrl('/login'), [
            'email' => $fresh->email,
            'password' => 'password',
        ])->assertRedirect(workspace_url($workspace->slug));
        $this->assertAuthenticatedAs($fresh);
    }

    #[Test]
    public function the_password_turns_it_off_too(): void
    {
        // Somebody whose phone is gone and whose recovery codes are in a drawer
        // should not need an administrator.
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');
        [$enrolled] = $this->withTwoFactor($user);

        $this->actingAs($enrolled)
            ->delete($this->workspaceUrl($workspace, '/settings/two-factor'), [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($enrolled->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function new_recovery_codes_replace_the_old_ones(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');
        [$enrolled, , $old] = $this->withTwoFactor($user);

        $fresh = $this->actingAs($enrolled)
            ->post($this->workspaceUrl($workspace, '/settings/two-factor/recovery-codes'))
            ->assertRedirect()
            ->baseResponse->getSession()->get('two_factor_recovery_codes');

        $this->assertCount(RecoveryCodes::COUNT, $fresh);
        $this->assertSame([], array_intersect($old, $fresh));

        $this->signOut();
        $this->signInWithPassword($enrolled);

        $this->post($this->centralUrl('/two-factor'), ['code' => $old[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // The control: one of the new ones works.
        $this->post($this->centralUrl('/two-factor'), ['code' => $fresh[0]])->assertRedirect();
        $this->assertAuthenticatedAs($enrolled->fresh());
    }

    #[Test]
    public function it_is_not_behind_a_plan_or_a_role(): void
    {
        /*
         * Security is not a feature tier. A paywalled second factor is a charge for
         * not being broken into, and the accounts that would decline to pay it are
         * the side projects with the reused password.
         */
        // Hosted, with no subscription and no trial, which is the cheapest a
        // workspace can be.
        config(['buggie.hosted' => true]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $workspace->forceFill(['trial_ends_at' => null])->save();

        foreach ([WorkspaceRole::Client, WorkspaceRole::Member] as $role) {
            $user = User::factory()->create();
            $this->join($workspace, $user, $role);

            $this->actingAs($user)
                ->get($this->workspaceUrl($workspace, '/settings/two-factor'))
                ->assertOk();

            $this->actingAs($user)
                ->post($this->workspaceUrl($workspace, '/settings/two-factor'))
                ->assertSessionHasNoErrors();

            $this->assertNotNull($user->fresh()->two_factor_secret);
        }
    }

    #[Test]
    public function nobody_can_set_up_a_second_factor_on_somebody_elses_account(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$victim] = $this->withTwoFactor();
        $this->join($workspace, $victim);

        // There is no route that takes a user: the account acted on is always the
        // one holding the session. Passing an id changes nothing.
        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, '/settings/two-factor'), [
                'user_id' => $victim->id,
                'password' => 'password',
            ]);

        $this->assertTrue($victim->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function a_guest_cannot_reach_the_settings_screen(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->get($this->workspaceUrl($workspace, '/settings/two-factor'))->assertRedirect();
        $this->post($this->workspaceUrl($workspace, '/settings/two-factor'))->assertRedirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * An account with a confirmed second factor.
     *
     * Built directly rather than through the enrolment endpoints: those are what the
     * tests above are checking, and a helper that depends on them would fail in two
     * places for one fault.
     *
     * @return array{0: User, 1: string, 2: array<int, string>}
     */
    private function withTwoFactor(?User $user = null): array
    {
        $user ??= User::factory()->create();

        $secret = $user->startTwoFactorEnrolment();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $codes = $user->replaceRecoveryCodes();

        return [$user->fresh(), $secret, $codes];
    }

    private function join(Workspace $workspace, User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);
    }

    /**
     * Put the test back to being nobody.
     *
     * flushSession() alone is not enough after actingAs(): that sets the user on the
     * guard directly, so the next request is still signed in and /login bounces it
     * away before any of this is exercised.
     */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function signInWithPassword(User $user): void
    {
        $this->post($this->centralUrl('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(central_url('/two-factor'));
    }

    private function codeFor(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::step() + $offset);
    }

    /**
     * Six digits that are not any of the three currently valid ones.
     *
     * Built from code(), which the RFC vectors pin down, and never from verify(),
     * which is the thing being tested — a helper that asks the verifier what is
     * wrong agrees with a broken verifier and proves nothing.
     */
    private function wrongCode(string $secret): string
    {
        $valid = array_map(
            fn (int $offset) => Totp::code($secret, Totp::step() + $offset),
            [-1, 0, 1],
        );

        $code = '000000';

        while (in_array($code, $valid, true)) {
            $code = str_pad((string) (((int) $code) + 1), 6, '0', STR_PAD_LEFT);
        }

        return $code;
    }
}
