<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Enums\NotificationReason;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Attachment;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Notifications\Notifier;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The boundaries a security review found open: one workspace reaching another's
 * grants, staff promoting themselves through invitation links, clients filing into
 * or learning about projects they do not hold, email about issues the recipient
 * cannot see, unthrottled sign-in, and files on internal notes.
 */
class AccessBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $acme;

    private User $owner;

    private Project $web;

    private Project $globex;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->acme, $this->owner] = $this->workspaceWithMember(slug: 'acme');
        [$this->web, $this->globex] = $this->tenant(fn () => [
            Project::factory()->create(['name' => 'Website', 'key' => 'WEB', 'slug' => 'web']),
            // Another customer's project in the same agency workspace.
            Project::factory()->create(['name' => 'Globex Portal', 'key' => 'GLX', 'slug' => 'globex-portal']),
        ]);

        $this->client = User::factory()->create(['email' => 'cleo@client.test']);
        $this->acme->members()->attach($this->client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($this->client->id, ['role' => ProjectRole::ClientManager->value]);
    }

    #[Test]
    public function removing_somebody_takes_only_this_workspaces_grants_and_only_from_a_member(): void
    {
        [$other, $otherOwner] = $this->workspaceWithMember(slug: 'other');
        $theirs = app(Tenancy::class)->run($other, fn () => Project::factory()->create(['key' => 'OTH']));
        $other->members()->attach($this->client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $theirs->clients()->attach($this->client->id, ['role' => ProjectRole::Client->value]);

        // A user of another workspace entirely: not this workspace's to remove.
        $stranger = User::factory()->create();
        $other->members()->attach($stranger->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $theirs->clients()->attach($stranger->id, ['role' => ProjectRole::Client->value]);

        $this->actingAs($this->owner)->delete($this->url("/settings/members/{$stranger->id}"))->assertNotFound();
        $this->assertTrue($theirs->clients()->whereKey($stranger->id)->exists(), 'A stranger lost their grant in another workspace.');

        $this->actingAs($this->owner)->delete($this->url("/settings/members/{$this->client->id}"))->assertSessionHasNoErrors();

        $this->assertFalse($this->web->clients()->whereKey($this->client->id)->exists());
        $this->assertTrue($theirs->clients()->whereKey($this->client->id)->exists(), 'Their grant in another workspace went too.');
    }

    #[Test]
    public function editing_a_clients_projects_leaves_their_other_workspaces_alone(): void
    {
        [$other] = $this->workspaceWithMember(slug: 'other');
        $theirs = app(Tenancy::class)->run($other, fn () => Project::factory()->create(['key' => 'OTH']));
        $theirs->clients()->attach($this->client->id, ['role' => ProjectRole::Client->value]);

        $this->actingAs($this->owner)
            ->patch($this->url("/settings/members/{$this->client->id}/projects"), ['project_ids' => [$this->globex->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->globex->id], $this->tenant(fn () => $this->client->projects()->pluck('projects.id')->all()));
        $this->assertTrue($theirs->clients()->whereKey($this->client->id)->exists(), 'Another workspace\'s grant was synced away.');
    }

    #[Test]
    public function only_those_who_invite_see_invitation_links_and_a_link_is_for_its_address(): void
    {
        $member = User::factory()->create();
        $this->acme->members()->attach($member->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        $invitation = $this->tenant(fn () => Invitation::forceCreate([
            'email' => 'new-admin@acme.test', 'role' => WorkspaceRole::Admin->value,
            'token' => str_repeat('a', 48), 'invited_by_id' => $this->owner->id, 'expires_at' => now()->addWeek(),
        ]));

        $this->actingAs($member)->get($this->url('/settings/members'))
            ->assertInertia(fn ($page) => $page->where('invitations.0.url', null));
        $this->actingAs($this->owner)->get($this->url('/settings/members'))
            ->assertInertia(fn ($page) => $page->where('invitations.0.url', fn ($url) => str_contains($url, $invitation->token)));

        // The member got hold of the link anyway, and signs in as somebody else.
        $sidekick = User::factory()->create(['email' => 'sidekick@elsewhere.test']);
        $this->actingAs($sidekick)->post($this->url("/invitations/{$invitation->token}"))->assertSessionHasErrors('invitation');
        $this->assertFalse($sidekick->belongsToWorkspace($this->acme));

        $right = User::factory()->create(['email' => 'New-Admin@acme.test']);
        $this->actingAs($right)->post($this->url("/invitations/{$invitation->token}"));
        $this->assertSame(WorkspaceRole::Admin, $right->membershipIn($this->acme));
    }

    #[Test]
    public function a_client_cannot_file_into_or_see_a_project_they_do_not_hold(): void
    {
        $before = $this->tenant(fn () => Issue::count());

        $this->actingAs($this->client)
            ->post($this->url('/issues'), ['project_id' => $this->globex->id, 'title' => 'Probe'])
            ->assertNotFound();
        $this->assertSame($before, $this->tenant(fn () => Issue::count()));

        // The New issue page offers only their own project, and a slug they do not
        // hold is simply not found.
        $props = $this->actingAs($this->client)->get($this->url('/issues/create'))->viewData('page')['props'];
        $this->assertSame('WEB', $props['project']['key']);
        $this->assertStringNotContainsString('Globex', json_encode(collect($props)->except('ziggy')->all()));
        $this->actingAs($this->client)->get($this->url('/issues/create?project=globex-portal'))->assertNotFound();

        // Filing into their own is still fine.
        $this->actingAs($this->client)
            ->post($this->url('/issues'), ['project_id' => $this->web->id, 'title' => 'The logo is squashed'])
            ->assertRedirect();
    }

    #[Test]
    public function a_clients_parent_key_is_never_a_way_to_learn_which_keys_exist(): void
    {
        $internal = $this->issue($this->web, 'Internal work', 'internal');

        $real = $this->actingAs($this->client)
            ->post($this->url('/issues'), ['project_id' => $this->web->id, 'title' => 'A', 'parent' => $internal->key]);
        $fake = $this->actingAs($this->client)
            ->post($this->url('/issues'), ['project_id' => $this->web->id, 'title' => 'B', 'parent' => 'WEB-999']);

        $this->assertSame($real->status(), $fake->status());
        $this->assertNull($this->tenant(fn () => Issue::where('title', 'A')->value('parent_id')));
    }

    #[Test]
    public function nobody_is_emailed_about_an_issue_they_cannot_open(): void
    {
        $internal = $this->issue($this->web, 'Internal refactor', 'internal');
        $shared = $this->issue($this->web, 'Homepage', 'client');

        // Mentioned in a note on an internal issue, and in an internal note on a shared one.
        foreach ([[$internal, false], [$shared, true]] as [$issue, $note]) {
            $this->tenant(fn () => app(AddComment::class)->handle($issue, [
                'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                    ['type' => 'mention', 'attrs' => ['id' => $this->client->id, 'label' => 'Cleo']],
                ]]]],
                'is_internal' => $note,
            ], $this->owner));
        }

        $this->assertSame(0, $this->tenant(fn () => PendingNotification::where('user_id', $this->client->id)->count()));
    }

    #[Test]
    public function a_digest_is_not_sent_to_somebody_who_lost_access_while_it_waited(): void
    {
        Notification::fake();
        $shared = $this->issue($this->web, 'Homepage', 'client');

        $this->tenant(fn () => app(Notifier::class)
            ->record($this->client, $shared, NotificationReason::Commented, $this->owner));
        $this->assertSame(1, $this->tenant(fn () => PendingNotification::count()));

        $this->web->clients()->detach($this->client->id);
        $this->travel(30)->minutes();
        $this->artisan('notifications:flush')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function signing_in_is_throttled(): void
    {
        $login = fn (string $password) => $this->post(central_url('login'), ['email' => $this->owner->email, 'password' => $password]);

        foreach (range(1, 5) as $i) {
            $login('wrong-'.$i)->assertSessionHasErrors('email');
        }

        // The sixth attempt is refused even with the right password.
        $login('password')->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many', session('errors')->first('email'));
        $this->assertGuest();
    }

    #[Test]
    public function a_file_on_an_internal_note_is_not_a_clients_to_download(): void
    {
        Storage::fake('local');
        $shared = $this->issue($this->web, 'Homepage', 'client');

        $attachment = $this->tenant(function () use ($shared) {
            $note = $shared->comments()->create([
                'user_id' => $this->owner->id, 'body' => ['type' => 'doc'], 'body_text' => 'Budget notes', 'is_internal' => true,
            ]);
            Storage::disk('local')->put('attachments/budget.txt', 'The client pays 40k.');

            return Attachment::forceCreate([
                'attachable_type' => $note->getMorphClass(), 'attachable_id' => $note->id,
                'uploaded_by_id' => $this->owner->id, 'disk' => 'local', 'path' => 'attachments/budget.txt',
                'filename' => 'budget.txt', 'mime' => 'text/plain', 'size' => 20,
            ]);
        });

        $this->actingAs($this->client)->get($this->url("/attachments/{$attachment->id}"))->assertNotFound();
        $this->actingAs($this->owner)->get($this->url("/attachments/{$attachment->id}"))->assertOk();
    }

    private function issue(Project $project, string $title, string $visibility): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle($project, ['title' => $title, 'visibility' => $visibility], $this->owner));
    }

    private function url(string $path): string
    {
        return $this->workspaceUrl($this->acme, $path);
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->acme, $callback);
    }
}
