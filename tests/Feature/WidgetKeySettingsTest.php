<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The widget key card on project settings.
 *
 * A widget key binds by its public key. The card called the routes with the numeric
 * id, so every toggle and the revoke button 404'd — while the checkbox had already
 * flipped on screen, so it looked saved. These pin the routes to the public key and
 * say who may use them.
 */
class WidgetKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private WidgetKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'acme');

        $this->key = app(Tenancy::class)->run($this->workspace, function () {
            $project = Project::factory()->create();

            return WidgetKey::factory()->create(['project_id' => $project->id]);
        });
    }

    /** @return array<string, array{0: WorkspaceRole}> */
    public static function managers(): array
    {
        return ['owner' => [WorkspaceRole::Owner], 'admin' => [WorkspaceRole::Admin]];
    }

    #[Test]
    #[DataProvider('managers')]
    public function the_settings_save_by_public_key(WorkspaceRole $role): void
    {
        $this->actingAs($this->as($role))
            ->patch($this->url($this->key->public_key), $this->settings([
                'allowed_origins' => ['https://uat.acme.test'],
                'require_email' => true,
                'capture_screenshot' => false,
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertSessionHas('success', 'Widget settings saved.');

        $key = $this->key->fresh();
        $this->assertSame(['https://uat.acme.test'], $key->allowed_origins);
        $this->assertTrue($key->require_email);
        $this->assertFalse($key->capture_screenshot);
        $this->assertFalse($key->is_active);
    }

    #[Test]
    #[DataProvider('managers')]
    public function a_key_is_revoked_by_public_key(WorkspaceRole $role): void
    {
        $this->actingAs($this->as($role))
            ->delete($this->url($this->key->public_key))
            ->assertRedirect()
            ->assertSessionHas('success', 'Widget key revoked.');

        $this->assertNull(WidgetKey::withoutGlobalScopes()->find($this->key->id));
    }

    #[Test]
    public function the_numeric_id_is_not_a_route_to_the_key(): void
    {
        // The regression itself: this is what the card used to send.
        $this->actingAs($this->owner)->patch($this->url((string) $this->key->id), $this->settings())->assertNotFound();
        $this->actingAs($this->owner)->delete($this->url((string) $this->key->id))->assertNotFound();
    }

    #[Test]
    public function a_client_is_refused(): void
    {
        $client = $this->as(WorkspaceRole::Client);

        $this->actingAs($client)->patch($this->url($this->key->public_key), $this->settings(['is_active' => false]))->assertForbidden();
        $this->actingAs($client)->delete($this->url($this->key->public_key))->assertForbidden();

        $this->assertTrue($this->key->fresh()->is_active);
    }

    #[Test]
    public function a_member_who_cannot_manage_projects_is_refused(): void
    {
        // Project settings are for owners and admins (ProjectPolicy::update), and the
        // widget key is a project setting. Pinned so a change to it is deliberate.
        $member = $this->as(WorkspaceRole::Member);

        $this->actingAs($member)->patch($this->url($this->key->public_key), $this->settings(['is_active' => false]))->assertForbidden();
        $this->actingAs($member)->delete($this->url($this->key->public_key))->assertForbidden();
    }

    #[Test]
    public function a_key_from_another_workspace_is_not_found(): void
    {
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $theirs = app(Tenancy::class)->run($globex, function () {
            $project = Project::factory()->create();

            return WidgetKey::factory()->create(['project_id' => $project->id]);
        });

        $this->actingAs($this->owner)->patch($this->url($theirs->public_key), $this->settings(['is_active' => false]))->assertNotFound();
        $this->actingAs($this->owner)->delete($this->url($theirs->public_key))->assertNotFound();

        $this->assertTrue($theirs->fresh()->is_active);
    }

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'allowed_origins' => [],
            'mode' => 'identified',
            'require_email' => false,
            'capture_screenshot' => true,
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function url(string $key): string
    {
        return $this->workspaceUrl($this->workspace, "/widget-keys/{$key}");
    }

    private function as(WorkspaceRole $role): User
    {
        if ($role === WorkspaceRole::Owner) {
            return $this->owner;
        }

        $user = User::factory()->create();
        $this->workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

        return $user;
    }
}
