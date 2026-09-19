<?php

namespace Tests\Feature;

use App\Enums\NotificationReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Notifications\Notifier;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function everything_is_on_until_somebody_turns_it_off(): void
    {
        $user = User::factory()->create();

        foreach (NotificationReason::cases() as $reason) {
            $this->assertTrue($user->wantsNotification($reason));
        }
    }

    #[Test]
    public function switching_one_off_actually_stops_it(): void
    {
        // The whole point. The preference was honoured from the start and had
        // nowhere to be set, so "opt-out" was true of the code and false in practice.
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');

        $watcher = User::factory()->create();
        $workspace->members()->attach($watcher->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($watcher)
            ->patch($this->workspaceUrl($workspace, '/settings/notifications'), [
                'reasons' => [
                    NotificationReason::Assigned->value => false,
                    NotificationReason::Commented->value => true,
                ],
            ])
            ->assertRedirect();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
        ]));

        app(Tenancy::class)->run($workspace, function () use ($watcher, $issue, $actor) {
            app(Notifier::class)->record($watcher, $issue, NotificationReason::Assigned, $actor);
            app(Notifier::class)->record($watcher, $issue, NotificationReason::Commented, $actor);
        });

        $this->assertDatabaseMissing('pending_notifications', [
            'user_id' => $watcher->id,
            'reason' => NotificationReason::Assigned->value,
        ]);

        // The control: the other one still arrives, so the first is off rather than
        // everything being broken.
        $this->assertDatabaseHas('pending_notifications', [
            'user_id' => $watcher->id,
            'reason' => NotificationReason::Commented->value,
        ]);
    }

    #[Test]
    public function an_omitted_reason_is_off_rather_than_left_alone(): void
    {
        // Unchecked boxes are not submitted, so anything missing has been turned off.
        // Treating absence as "no change" would make boxes impossible to untick.
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->patch($this->workspaceUrl($workspace, '/settings/notifications'), [
                'reasons' => [NotificationReason::Mentioned->value => true],
            ])
            ->assertRedirect();

        $fresh = $user->fresh();

        $this->assertTrue($fresh->wantsNotification(NotificationReason::Mentioned));
        $this->assertFalse($fresh->wantsNotification(NotificationReason::Assigned));
    }

    #[Test]
    public function an_invented_reason_cannot_be_stored(): void
    {
        // The column is rebuilt from the enum, so a stale key from an old form or an
        // invented one never reaches it.
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($user)
            ->patch($this->workspaceUrl($workspace, '/settings/notifications'), [
                'reasons' => ['not_a_reason' => true, NotificationReason::Assigned->value => true],
            ])
            ->assertRedirect();

        $this->assertArrayNotHasKey('not_a_reason', (array) $user->fresh()->notification_settings);
    }

    #[Test]
    public function preferences_follow_the_person_between_workspaces(): void
    {
        // Somebody invited to four client workspaces should not have to switch the
        // same thing off four times.
        [$acme, $user] = $this->workspaceWithMember(slug: 'acme');
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $globex->members()->attach($user->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch($this->workspaceUrl($acme, '/settings/notifications'), ['reasons' => []])
            ->assertRedirect();

        $this->actingAs($user)
            ->get($this->workspaceUrl($globex, '/settings/notifications'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reasons.0.enabled', false));
    }

    #[Test]
    public function a_client_has_preferences_too(): void
    {
        // They get notifications, so they get a say in them.
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/settings/notifications'))
            ->assertOk();
    }
}
