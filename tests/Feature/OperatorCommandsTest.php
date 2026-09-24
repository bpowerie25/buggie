<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `buggie:operator` and `buggie:audit-signups`: running an install, and finding out
 * what strangers set up on it before sign-up was closed.
 */
class OperatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buggie.hosted' => false, 'buggie.operators' => []]);
    }

    #[Test]
    public function it_promotes_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'Ops@Matrix.test']);

        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test'])
            ->expectsOutputToContain('is now an operator')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_operator);
    }

    #[Test]
    public function it_creates_the_account_when_there_is_none(): void
    {
        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--name' => 'Matrix Ops'])
            ->expectsQuestion('Password (leave blank to generate one)', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::where('email', 'ops@matrix.test')->sole();
        $this->assertTrue($user->is_operator);
        $this->assertSame('Matrix Ops', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    #[Test]
    public function without_a_terminal_it_generates_a_password_and_shows_it_once(): void
    {
        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--no-interaction' => true])
            ->expectsOutputToContain('Password:')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'ops@matrix.test')->sole()->is_operator);
    }

    #[Test]
    public function the_new_operator_can_create_a_workspace_on_an_invite_only_install(): void
    {
        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--no-interaction' => true]);

        $this->actingAs(User::where('email', 'ops@matrix.test')->sole())
            ->post($this->centralUrl('/workspaces'), ['name' => 'Matrix', 'slug' => 'matrix'])
            ->assertRedirect(workspace_url('matrix'));
    }

    #[Test]
    public function it_revokes_and_says_when_the_environment_still_names_them(): void
    {
        $user = User::factory()->operator()->create(['email' => 'ops@matrix.test']);

        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_operator);

        config(['buggie.operators' => ['ops@matrix.test']]);

        $this->artisan('buggie:operator', ['email' => 'ops@matrix.test', '--revoke' => true])
            ->expectsOutputToContain('still named in BUGGIE_OPERATORS')
            ->assertFailed();
    }

    #[Test]
    public function it_lists_operators_and_why(): void
    {
        config(['buggie.operators' => ['named@matrix.test', 'typo@matrx.test']]);

        User::factory()->operator()->create(['email' => 'stored@matrix.test']);
        User::factory()->create(['email' => 'named@matrix.test']);
        User::factory()->create(['email' => 'nobody@example.test']);

        $this->artisan('buggie:operator', ['--list' => true])
            ->expectsTable(['Email', 'Name', 'Because'], [
                ['stored@matrix.test', User::where('email', 'stored@matrix.test')->value('name'), 'stored'],
                ['named@matrix.test', User::where('email', 'named@matrix.test')->value('name'), 'BUGGIE_OPERATORS'],
                ['typo@matrx.test', '—', 'BUGGIE_OPERATORS (no account yet)'],
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function the_audit_lists_strays_and_changes_nothing(): void
    {
        $operator = User::factory()->operator()->create(['email' => 'ops@matrix.test']);
        $ours = Workspace::factory()->create(['slug' => 'kennco', 'owner_id' => $operator->id]);
        $ours->members()->attach($operator->id, ['role' => 'owner', 'joined_at' => now()]);

        // A stranger who registered and set up shop.
        [$squat, $stranger] = $this->workspaceWithMember(WorkspaceRole::Owner, 'free-stuff');
        $stranger->forceFill(['email' => 'stranger@spam.test'])->save();

        // One who registered and never went further.
        User::factory()->create(['email' => 'loner@spam.test']);

        $this->artisan('buggie:audit-signups')
            ->expectsOutputToContain('loner@spam.test')
            ->expectsOutputToContain('free-stuff')
            ->doesntExpectOutputToContain('kennco')
            ->doesntExpectOutputToContain('ops@matrix.test')
            ->assertSuccessful();

        $this->assertSame(3, User::count());
        $this->assertSame(2, Workspace::count());
    }
}
