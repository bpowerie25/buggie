<?php

namespace Tests\Feature;

use App\Actions\CreateWorkspace;
use App\Actions\InviteToWorkspace;
use App\Enums\RegistrationMode;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\WorkspaceController;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Registration\Registration;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may make an account, and who may make a workspace.
 *
 * A self-hosted install used to let any stranger register and then create workspaces
 * of their own: storage, outbound mail and a subdomain on somebody else's server.
 * These pin down that the door is shut unless the install opens it, and that the ways
 * in which are meant to work — invitations above all — still do.
 */
class RegistrationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buggie.hosted' => false,
            'buggie.registration' => null,
            'buggie.operators' => ['ops@matrix.test'],
        ]);

        // Somebody already has an account, so none of this is the first run, which
        // has its own rule and its own tests.
        User::factory()->create(['email' => 'ops@matrix.test']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function modes(): array
    {
        return [
            'open' => ['open', true],
            'invite' => ['invite', false],
            'request' => ['request', false],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function modeNames(): array
    {
        return array_map(fn (array $case) => [$case[0]], self::modes());
    }

    #[Test]
    #[DataProvider('modes')]
    public function the_registration_form_follows_the_mode(string $mode, bool $open): void
    {
        $this->setMode($mode);

        $response = $this->get($this->centralUrl('/register'));

        if ($open) {
            $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/register'));

            return;
        }

        // A page that explains, not a 404.
        $response->assertForbidden()->assertInertia(fn ($page) => $page
            ->component('auth/registration-closed')
            ->where('requestAccessUrl', $mode === 'request' ? central_url('request-access') : null));
    }

    #[Test]
    #[DataProvider('modes')]
    public function registering_follows_the_mode(string $mode, bool $open): void
    {
        $this->setMode($mode);

        $response = $this->post($this->centralUrl('/register'), $this->newcomer());

        if ($open) {
            $response->assertRedirect(route('workspaces.create'));
            $this->assertAuthenticated();
            $this->assertDatabaseHas('users', ['email' => 'stranger@example.com']);

            return;
        }

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    #[Test]
    #[DataProvider('modeNames')]
    public function an_operator_may_create_a_workspace_in_every_mode(string $mode): void
    {
        $this->setMode($mode);
        $operator = User::where('email', 'ops@matrix.test')->firstOrFail();

        $this->actingAs($operator)->get($this->centralUrl('/workspaces/create'))->assertOk();

        $this->actingAs($operator)
            ->post($this->centralUrl('/workspaces'), ['name' => 'Kennco', 'slug' => 'kennco'])
            ->assertRedirect(workspace_url('kennco'));

        $this->assertDatabaseHas('workspaces', ['slug' => 'kennco', 'owner_id' => $operator->id]);
    }

    #[Test]
    #[DataProvider('modes')]
    public function anybody_else_may_create_one_only_when_open(string $mode, bool $open): void
    {
        $this->setMode($mode);
        $user = User::factory()->create();

        $form = $this->actingAs($user)->get($this->centralUrl('/workspaces/create'));
        $store = $this->actingAs($user)
            ->post($this->centralUrl('/workspaces'), ['name' => 'Squat', 'slug' => 'squat']);

        if ($open) {
            $form->assertOk();
            $store->assertRedirect(workspace_url('squat'));
            $this->assertDatabaseHas('workspaces', ['slug' => 'squat']);

            return;
        }

        $form->assertForbidden();
        $store->assertForbidden();
        $this->assertDatabaseMissing('workspaces', ['slug' => 'squat']);
    }

    #[Test]
    public function the_action_refuses_too_so_no_other_entry_point_can_skip_the_check(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(CreateWorkspace::class)->handle($user, 'Squat', 'squat');
    }

    #[Test]
    public function the_create_button_is_not_offered_to_somebody_who_would_be_refused(): void
    {
        $user = User::factory()->create();

        // No workspace and not allowed to make one: the picker explains, rather than
        // bouncing them to a form that refuses.
        $this->actingAs($user)->get($this->centralUrl('/'))
            ->assertRedirect(route('workspaces.index'));

        $this->actingAs($user)->get($this->centralUrl('/workspaces'))
            ->assertInertia(fn ($page) => $page
                ->component('workspaces/index')
                ->where('canCreate', false));
    }

    #[Test]
    public function sign_up_links_are_not_offered_when_sign_up_is_closed(): void
    {
        $this->get($this->centralUrl('/login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', false));

        $this->get($this->centralUrl('/'))
            ->assertInertia(fn ($page) => $page->where('canRegister', false));

        $this->setMode('open');

        $this->get($this->centralUrl('/login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', true));
    }

    #[Test]
    public function an_invitation_still_brings_a_brand_new_person_in(): void
    {
        // The whole journey, on an install where nobody can otherwise register.
        Notification::fake();
        $this->setMode('invite');

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'kennco');

        $invitation = app(Tenancy::class)->run($workspace, fn () => app(InviteToWorkspace::class)
            ->handle('new.person@kennco.test', WorkspaceRole::Member, [], $owner));

        $this->get($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(central_url('register'));

        $this->get($this->centralUrl('/register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/register'));

        $this->post($this->centralUrl('/register'), [
            'name' => 'New Person',
            'email' => 'new.person@kennco.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect($this->workspaceUrl($workspace, '/invitations/'.$invitation->token));

        $this->post($this->workspaceUrl($workspace, '/invitations/'.$invitation->token))
            ->assertRedirect(workspace_url($workspace->slug));

        $this->assertTrue(
            User::where('email', 'new.person@kennco.test')->firstOrFail()->belongsToWorkspace($workspace),
        );
    }

    #[Test]
    public function the_hosted_service_stays_open_by_default(): void
    {
        config(['buggie.hosted' => true]);

        $this->assertSame(RegistrationMode::Open, app(Registration::class)->mode());

        $this->get($this->centralUrl('/register'))->assertOk();

        $this->post($this->centralUrl('/register'), $this->newcomer())
            ->assertRedirect(route('workspaces.create'));

        $user = User::where('email', 'stranger@example.com')->firstOrFail();

        $this->actingAs($user)
            ->post($this->centralUrl('/workspaces'), ['name' => 'Acme', 'slug' => 'acme'])
            ->assertRedirect(workspace_url('acme'));
    }

    #[Test]
    public function an_upgraded_self_hosted_install_with_nothing_stored_is_invite_only(): void
    {
        // The fix, as an upgrade delivers it: no setting, no env, self-hosted.
        $this->assertSame(RegistrationMode::Invite, app(Registration::class)->mode());
    }

    #[Test]
    public function closing_sign_up_takes_nothing_away_from_existing_members(): void
    {
        [$workspace, $member] = $this->workspaceWithMember(WorkspaceRole::Member, 'kennco');

        $this->setMode('invite');

        $this->actingAs($member)->get($this->workspaceUrl($workspace, '/'))->assertOk();

        $this->post($this->centralUrl('/logout'));

        $this->post($this->centralUrl('/login'), ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect(workspace_url($workspace->slug));
    }

    #[Test]
    public function the_environment_overrides_the_screen(): void
    {
        app(Settings::class)->put([Registration::SETTING => 'open']);

        config(['buggie.registration' => 'invite']);
        $this->assertSame(RegistrationMode::Invite, app(Registration::class)->mode());
        $this->get($this->centralUrl('/register'))->assertForbidden();

        config(['buggie.registration' => 'OPEN']);
        $this->assertSame(RegistrationMode::Open, app(Registration::class)->mode());
        $this->get($this->centralUrl('/register'))->assertOk();
    }

    #[Test]
    public function a_mistyped_environment_value_closes_the_door_rather_than_opening_it(): void
    {
        config(['buggie.hosted' => true, 'buggie.registration' => 'opne']);

        $this->assertSame(RegistrationMode::Invite, app(Registration::class)->mode());
    }

    #[Test]
    public function an_operator_sets_the_mode_on_the_instance_screen(): void
    {
        [$workspace, $operator] = $this->operatorWorkspace();

        $this->actingAs($operator)
            ->get($this->workspaceUrl($workspace, '/settings/instance'))
            ->assertInertia(fn ($page) => $page
                ->where('registration.mode', 'invite')
                ->where('registration.from_env', false));

        $this->actingAs($operator)
            ->patch($this->workspaceUrl($workspace, '/settings/instance/registration'), ['mode' => 'request'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RegistrationMode::Request, app(Registration::class)->mode());
    }

    #[Test]
    public function the_screen_will_not_pretend_to_override_the_environment(): void
    {
        config(['buggie.registration' => 'invite']);
        [$workspace, $operator] = $this->operatorWorkspace();

        $this->actingAs($operator)
            ->patch($this->workspaceUrl($workspace, '/settings/instance/registration'), ['mode' => 'open'])
            ->assertSessionHasErrors('registration');

        $this->assertNull(app(Settings::class)->get(Registration::SETTING));
    }

    #[Test]
    public function a_workspace_owner_who_is_not_an_operator_cannot_change_it(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'kennco');

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, '/settings/instance/registration'), ['mode' => 'open'])
            ->assertForbidden();

        $this->assertSame(RegistrationMode::Invite, app(Registration::class)->mode());
    }

    #[Test]
    public function no_other_route_creates_an_account_or_a_workspace(): void
    {
        // The API and everything else: if a new route reaches either of these, it has
        // to be looked at, and this is where it gets noticed.
        $routes = collect(Route::getRoutes()->getRoutes());

        $reaching = fn (string $action) => $routes
            ->filter(fn ($route) => $route->getActionName() === $action)
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(['POST register'], $reaching(RegisteredUserController::class.'@store'));
        $this->assertSame(['POST workspaces'], $reaching(WorkspaceController::class.'@store'));

        $register = $routes->first(fn ($route) => $route->getActionName() === RegisteredUserController::class.'@store');
        $this->assertContains('registration', $register->gatherMiddleware());
    }

    private function setMode(string $mode): void
    {
        app(Settings::class)->put([Registration::SETTING => $mode]);
    }

    /** @return array<string, string> */
    private function newcomer(): array
    {
        return [
            'name' => 'A Stranger',
            'email' => 'stranger@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ];
    }

    /** @return array{0: Workspace, 1: User} */
    private function operatorWorkspace(): array
    {
        $operator = User::where('email', 'ops@matrix.test')->firstOrFail();

        $workspace = Workspace::factory()->create(['owner_id' => $operator->id, 'slug' => 'matrix']);
        $workspace->members()->attach($operator->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$workspace, $operator];
    }
}
