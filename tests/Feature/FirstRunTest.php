<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Registration\Admission;
use App\Support\Registration\Registration;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An install with no accounts at all lets exactly one person register, whatever the
 * mode, and that person runs it.
 */
class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buggie.hosted' => false, 'buggie.registration' => null, 'buggie.operators' => []]);
    }

    #[Test]
    public function the_first_person_registers_whatever_the_mode_and_becomes_the_operator(): void
    {
        foreach (['invite', 'request'] as $mode) {
            $this->refreshDatabaseForMode($mode);

            $this->get($this->centralUrl('/register'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('auth/register')->where('firstRun', true));

            $this->post($this->centralUrl('/register'), $this->person('first@matrix.test'))
                ->assertRedirect(route('workspaces.create'));

            $first = User::where('email', 'first@matrix.test')->sole();
            $this->assertTrue($first->is_operator, "The first account was not made operator in [{$mode}] mode.");

            // And creates the first workspace.
            $this->post($this->centralUrl('/workspaces'), ['name' => 'Matrix', 'slug' => 'matrix'])
                ->assertRedirect(workspace_url('matrix'));

            // After which the mode applies.
            $this->post($this->centralUrl('/logout'));

            $this->get($this->centralUrl('/register'))->assertForbidden();
            $this->post($this->centralUrl('/register'), $this->person('second@example.test'))->assertForbidden();
            $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
        }
    }

    #[Test]
    public function only_one_of_two_racing_registrations_wins(): void
    {
        // The interleaving that matters: both visitors pass the middleware's check on
        // an empty install, and the other one's claim lands between that check and
        // this one's transaction. Postgres serialises the two inserts on the claim's
        // primary key, so this is the order a real race resolves to for the loser.
        $this->app->instance(Registration::class, new class(app(Settings::class)) extends Registration
        {
            private int $calls = 0;

            public function admits(Request $request): ?Admission
            {
                $admission = parent::admits($request);

                // Second call is the controller's, inside its transaction.
                if (++$this->calls === 2) {
                    DB::table('app_settings')->insert([
                        'key' => self::FIRST_RUN_CLAIM,
                        'value' => json_encode('the other request'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $admission;
            }
        });

        $this->post($this->centralUrl('/register'), $this->person('loser@example.test'))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('auth/registration-closed'));

        $this->assertDatabaseMissing('users', ['email' => 'loser@example.test']);
        $this->assertGuest();
    }

    #[Test]
    public function a_claimed_first_run_is_not_offered_again_even_before_the_account_exists(): void
    {
        // The winner has claimed but not yet committed its account.
        app(Registration::class)->claimFirstRun();

        $this->assertFalse(app(Registration::class)->isFirstRun());
        $this->get($this->centralUrl('/register'))->assertForbidden();
    }

    #[Test]
    public function the_claim_is_taken_exactly_once(): void
    {
        $registration = app(Registration::class);

        $this->assertTrue($registration->claimFirstRun());
        $this->assertFalse($registration->claimFirstRun());
    }

    #[Test]
    public function a_failed_first_registration_does_not_use_up_the_first_run(): void
    {
        $this->post($this->centralUrl('/register'), [...$this->person('first@matrix.test'), 'password_confirmation' => 'nope'])
            ->assertSessionHasErrors('password');

        $this->post($this->centralUrl('/register'), $this->person('first@matrix.test'))
            ->assertRedirect(route('workspaces.create'));

        $this->assertTrue(User::where('email', 'first@matrix.test')->sole()->is_operator);
    }

    #[Test]
    public function an_install_whose_accounts_were_all_deleted_does_not_reopen(): void
    {
        $this->post($this->centralUrl('/register'), $this->person('first@matrix.test'));
        $this->post($this->centralUrl('/logout'));

        User::query()->delete();

        $this->get($this->centralUrl('/register'))->assertForbidden();
    }

    #[Test]
    public function the_first_account_on_the_hosted_service_is_a_customer_like_any_other(): void
    {
        config(['buggie.hosted' => true]);

        $this->get($this->centralUrl('/register'))
            ->assertInertia(fn ($page) => $page->where('firstRun', false));

        $this->post($this->centralUrl('/register'), $this->person('first@customer.test'));

        $this->assertFalse(User::where('email', 'first@customer.test')->sole()->is_operator);
    }

    #[Test]
    public function an_account_made_from_the_command_line_ends_the_first_run(): void
    {
        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--no-interaction' => true])
            ->assertSuccessful();

        $this->get($this->centralUrl('/register'))->assertForbidden();
    }

    #[Test]
    public function upgrading_marks_the_account_the_old_rule_made_operator(): void
    {
        // Replays the migration against an install from before it: nobody named in
        // BUGGIE_OPERATORS, so the lowest id was the operator, and still is.
        $migration = require database_path('migrations/2026_09_24_110000_add_is_operator_to_users_table.php');
        $migration->down();

        $first = DB::table('users')->insertGetId($this->row('first@matrix.test'));
        $later = DB::table('users')->insertGetId($this->row('later@example.test'));

        $migration->up();

        $this->assertTrue((bool) DB::table('users')->where('id', $first)->value('is_operator'));
        $this->assertFalse((bool) DB::table('users')->where('id', $later)->value('is_operator'));
    }

    #[Test]
    public function upgrading_marks_nobody_when_operators_are_named_or_hosted(): void
    {
        $migration = require database_path('migrations/2026_09_24_110000_add_is_operator_to_users_table.php');

        foreach ([['buggie.operators' => ['ops@matrix.test']], ['buggie.hosted' => true]] as $config) {
            config(['buggie.hosted' => false, 'buggie.operators' => [], ...$config]);

            $migration->down();
            DB::table('users')->delete();
            DB::table('users')->insert($this->row('first@example.test'));
            $migration->up();

            $this->assertSame(0, DB::table('users')->where('is_operator', true)->count());
        }
    }

    private function refreshDatabaseForMode(string $mode): void
    {
        DB::table('workspace_user')->delete();
        Workspace::withTrashed()->forceDelete();
        User::query()->delete();
        DB::table('app_settings')->delete();

        app(Settings::class)->put([Registration::SETTING => $mode]);
    }

    /** @return array<string, string> */
    private function person(string $email): array
    {
        return [
            'name' => 'Some One',
            'email' => $email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $email): array
    {
        return [
            'name' => 'Some One',
            'email' => $email,
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
