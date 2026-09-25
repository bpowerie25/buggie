<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\UpdateIssue;
use App\Enums\IssueVisibility;
use App\Enums\NotificationReason;
use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Models\InAppNotification;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The in-app notification list.
 *
 * Two things are being asserted, and the second is the one that matters. The first
 * is that a notification arrives, gets counted, and can be marked read. The second
 * is that it stops arriving the moment the person stops being allowed to see the
 * issue it is about — which can happen long after the row was written, and which no
 * amount of care at write time can cover.
 *
 * Every "they do not see it" here is paired with a "they do see it". A visibility
 * test with no control passes perfectly when the feature is broken and nobody sees
 * anything at all.
 */
class InAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- the basics

    #[Test]
    public function a_watcher_is_told_and_the_actor_is_not(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);
            $issue->watch($actor, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('the button is still broken'),
                'is_internal' => true,
            ], $actor);
        });

        $rows = InAppNotification::withoutGlobalScopes()->get();

        // The control: somebody was told, so "the actor was not told" is a fact
        // about the actor rather than about an empty table.
        $this->assertSame([$watcher->id], $rows->pluck('user_id')->all());
        $this->assertSame(NotificationReason::Commented->value, $rows->first()->reason);
        $this->assertSame($actor->id, $rows->first()->actor_id);
        $this->assertNull($rows->first()->read_at, 'A new notification should arrive unread.');
    }

    #[Test]
    public function the_list_shows_what_happened_and_links_to_the_issue(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        $issue = app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            app(UpdateIssue::class)->handle($issue, ['assignee_id' => $watcher->id], $actor);

            return $issue;
        });

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('notifications/index')
                ->has('notifications', 1)
                ->where('notifications.0.issue.key', $issue->key)
                ->where('notifications.0.url', '/issues/'.$issue->key)
                ->where('notifications.0.sentence', $actor->name.' assigned this to you.')
                ->where('notifications.0.read', false)
                ->where('unread', 1));
    }

    #[Test]
    public function the_unread_count_counts_only_this_persons_unread_notifications(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);
        $bystander = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            foreach (['one', 'two'] as $text) {
                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc($text), 'is_internal' => true,
                ], $actor);
            }
        });

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('notificationCount', 2));

        // Somebody else's badge is not this person's badge.
        $this->actingAs($bystander)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('notificationCount', 0));

        // A read row stops counting. Without this, "the count is 2" would also hold
        // for an implementation that ignores read_at entirely.
        InAppNotification::withoutGlobalScopes()->first()->markRead();

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('notificationCount', 1));
    }

    // ------------------------------------------------------------ marking read

    #[Test]
    public function opening_one_marks_it_read_and_goes_to_the_issue(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        $issue = app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('hello'), 'is_internal' => true,
            ], $actor);

            return $issue;
        });

        $row = InAppNotification::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($watcher)
            ->post($this->workspaceUrl($workspace, '/notifications/'.$row->id.'/read'))
            ->assertRedirect('/issues/'.$issue->key);

        $this->assertNotNull($row->fresh()->read_at);

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertInertia(fn ($page) => $page->where('notificationCount', 0));
    }

    #[Test]
    public function marking_all_read_empties_the_badge_and_keeps_the_list(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            foreach (['one', 'two', 'three'] as $text) {
                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc($text), 'is_internal' => true,
                ], $actor);
            }
        });

        $this->actingAs($watcher)
            ->post($this->workspaceUrl($workspace, '/notifications/read'))
            ->assertRedirect();

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertInertia(fn ($page) => $page
                // Read, not gone: the list is a history, not a queue.
                ->has('notifications', 3)
                ->where('notifications.0.read', true)
                ->where('unread', 0));
    }

    #[Test]
    public function somebody_elses_notification_cannot_be_marked_read(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);
        $nosy = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('hello'), 'is_internal' => true,
            ], $actor);
        });

        $row = InAppNotification::withoutGlobalScopes()->firstOrFail();
        $url = $this->workspaceUrl($workspace, '/notifications/'.$row->id.'/read');

        // 404, not 403: the redirect target is an issue key, so confirming the row
        // exists would confirm the issue does.
        $this->actingAs($nosy)->post($url)->assertNotFound();
        $this->assertNull($row->fresh()->read_at);

        // The control: the owner can.
        $this->actingAs($watcher)->post($url)->assertRedirect();
        $this->assertNotNull($row->fresh()->read_at);
    }

    // ----------------------------------------------------- visibility, at read

    #[Test]
    public function losing_workspace_membership_stops_the_notifications_being_seen(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            // Deliberately a public comment. An internal one would be withheld by
            // the internal-text guard whatever the visibility rules did, and this
            // test would then pass with those rules deleted.
            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('hello'), 'is_internal' => false,
            ], $actor);
        });

        // The control, before anything is taken away.
        $this->assertSame(1, $this->visibleCount($workspace, $watcher));
        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertInertia(fn ($page) => $page->has('notifications', 1));

        $workspace->members()->detach($watcher->id);

        // The row still exists — nothing was deleted — but it is no longer theirs
        // to see, and that is decided when they ask rather than when it was written.
        $this->assertSame(1, InAppNotification::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->visibleCount($workspace, $watcher));

        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertNotFound();
    }

    #[Test]
    public function a_client_sees_their_own_issue_and_not_one_they_were_never_granted(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $client = $this->member($workspace, WorkspaceRole::Client);

        [$theirs, $someoneElses] = app(Tenancy::class)->run($workspace, function () use ($client, $staff) {
            $granted = Project::factory()->create(['key' => 'WEB']);
            $other = Project::factory()->create(['key' => 'OPS']);

            $theirs = Issue::factory()->clientVisible()->create(['project_id' => $granted->id]);
            $someoneElses = Issue::factory()->clientVisible()->create(['project_id' => $other->id]);

            $granted->clients()->attach($client->id, ['role' => 'client']);

            $theirs->watch($client, WatchReason::Reported);
            app(AddComment::class)->handle($theirs, [
                'body' => $this->doc('we are on it'), 'is_internal' => false,
            ], $staff);

            // Recording now refuses to notify somebody about an issue they cannot open,
            // so this row stands for one written back when they could — access changes
            // after the fact, and the list must still decide at read time.
            $this->legacyRow($client, $someoneElses, $staff);

            return [$theirs, $someoneElses];
        });

        // Both rows were written — the client watches both issues — so this is a
        // read-time filter being asserted, not a write-time one.
        $this->assertSame(2, InAppNotification::withoutGlobalScopes()
            ->where('user_id', $client->id)->count());

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notifications', 1)
                ->where('notifications.0.issue.key', $theirs->key))
            // The other project's key must not appear anywhere in the payload:
            // in an agency workspace, one client learning another's issue exists
            // is the leak, even with none of the text.
            ->assertDontSee($someoneElses->key);

        $this->assertSame(1, $this->visibleCount($workspace, $client));
    }

    #[Test]
    public function an_issue_that_stops_being_client_visible_stops_being_notified_about(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $client = $this->member($workspace, WorkspaceRole::Client);

        $issue = app(Tenancy::class)->run($workspace, function () use ($client, $staff) {
            $project = Project::factory()->create(['key' => 'WEB']);
            $project->clients()->attach($client->id, ['role' => 'client']);

            $issue = Issue::factory()->clientVisible()->create(['project_id' => $project->id]);
            $issue->watch($client, WatchReason::Reported);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('we are on it'), 'is_internal' => false,
            ], $staff);

            return $issue;
        });

        // The control: while it is shared, they see it.
        $this->assertSame(1, $this->visibleCount($workspace, $client));

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['visibility' => IssueVisibility::Internal->value], $staff));

        $this->assertSame(0, $this->visibleCount($workspace, $client));

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertInertia(fn ($page) => $page->has('notifications', 0));
    }

    #[Test]
    public function a_demoted_staff_member_stops_seeing_the_internal_comment_they_were_told_about(): void
    {
        Notification::fake();
        [$workspace, $author] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $watcher = $this->member($workspace);

        $issue = app(Tenancy::class)->run($workspace, function () use ($author, $watcher) {
            $project = Project::factory()->create(['key' => 'WEB']);
            $project->clients()->attach($watcher->id, ['role' => 'client']);

            $issue = Issue::factory()->clientVisible()->create(['project_id' => $project->id]);
            $issue->watch($watcher, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Dave broke the migration again'), 'is_internal' => true,
            ], $author);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('We are looking into it'), 'is_internal' => false,
            ], $author);

            return $issue;
        });

        // The control: as staff they are told about both, internal text included.
        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertInertia(fn ($page) => $page->has('notifications', 2))
            ->assertSee('Dave broke the migration again');

        $workspace->members()->updateExistingPivot($watcher->id, [
            'role' => WorkspaceRole::Client->value,
        ]);

        // The issue is still client-visible and they still hold the project, so the
        // issue filter alone would let the internal excerpt through.
        $this->actingAs($watcher)
            ->get($this->workspaceUrl($workspace, '/notifications'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications', 1))
            ->assertDontSee('Dave broke the migration again')
            ->assertSee('We are looking into it');

        unset($issue);
    }

    #[Test]
    public function marking_all_read_does_not_touch_notifications_this_person_cannot_see(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $client = $this->member($workspace, WorkspaceRole::Client);

        $hidden = app(Tenancy::class)->run($workspace, function () use ($client, $staff) {
            $granted = Project::factory()->create(['key' => 'WEB']);
            $granted->clients()->attach($client->id, ['role' => 'client']);
            $other = Project::factory()->create(['key' => 'OPS']);

            $visible = Issue::factory()->clientVisible()->create(['project_id' => $granted->id]);
            $hidden = Issue::factory()->clientVisible()->create(['project_id' => $other->id]);

            $visible->watch($client, WatchReason::Reported);
            app(AddComment::class)->handle($visible, [
                'body' => $this->doc('an update'), 'is_internal' => false,
            ], $staff);

            // Written as if from before they lost sight of it; see legacyRow().
            $this->legacyRow($client, $hidden, $staff);

            return $hidden;
        });

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/notifications/read'))
            ->assertRedirect();

        // The one they could see is read; the one they could not stays unread, so
        // that a grant restored tomorrow does not restore it pre-dismissed.
        $this->assertNull(
            InAppNotification::withoutGlobalScopes()
                ->where('issue_id', $hidden->id)->firstOrFail()->read_at,
        );
        $this->assertSame(0, $this->visibleCount($workspace, $client, unreadOnly: true));
    }

    #[Test]
    public function notifications_do_not_cross_workspaces(): void
    {
        Notification::fake();
        [$acme, $acmeActor] = $this->workspaceWithMember(slug: 'acme');
        [$globex, $globexActor] = $this->workspaceWithMember(slug: 'globex');

        $person = User::factory()->create();

        foreach ([[$acme, $acmeActor], [$globex, $globexActor]] as [$workspace, $actor]) {
            $workspace->members()->attach($person->id, [
                'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
            ]);

            app(Tenancy::class)->run($workspace, function () use ($actor, $person) {
                $issue = $this->issue();
                $issue->watch($person, WatchReason::Manual);

                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc('hello'), 'is_internal' => true,
                ], $actor);
            });
        }

        $this->assertSame(2, InAppNotification::withoutGlobalScopes()->count());

        foreach ([$acme, $globex] as $workspace) {
            $this->actingAs($person)
                ->get($this->workspaceUrl($workspace, '/notifications'))
                ->assertInertia(fn ($page) => $page->has('notifications', 1));
        }
    }

    // ------------------------------------------------ living past the email

    #[Test]
    public function the_in_app_row_outlives_the_digest_that_was_sent_from_it(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('hello'), 'is_internal' => true,
            ], $actor);
        });

        $this->assertSame(1, PendingNotification::withoutGlobalScopes()->count());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('notifications:flush')->assertSuccessful();
        Carbon::setTestNow();

        // The send queue is emptied, which is the whole reason this is a second
        // table: a list that disappeared when the email went out would be a list
        // that was empty every time anybody looked at it.
        $this->assertSame(0, PendingNotification::withoutGlobalScopes()->count());
        $this->assertSame(1, InAppNotification::withoutGlobalScopes()->count());
        $this->assertNull(InAppNotification::withoutGlobalScopes()->first()->read_at);
    }

    #[Test]
    public function old_notifications_are_pruned_and_recent_ones_are_not(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');
        $watcher = $this->member($workspace);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = $this->issue();
            $issue->watch($watcher, WatchReason::Manual);

            foreach (['old', 'recent'] as $text) {
                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc($text), 'is_internal' => true,
                ], $actor);
            }
        });

        $rows = InAppNotification::withoutGlobalScopes()->orderBy('id')->get();
        $rows->first()->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->artisan('buggie:prune')->assertSuccessful();

        $this->assertSame(
            [$rows->last()->id],
            InAppNotification::withoutGlobalScopes()->pluck('id')->all(),
        );
    }

    // ------------------------------------------------------------------ helpers

    /** Notifications this person may actually be shown, right now. */
    private function visibleCount(Workspace $workspace, User $user, bool $unreadOnly = false): int
    {
        return app(Tenancy::class)->run($workspace, fn () => InAppNotification::query()
            ->visibleTo($user)
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->count());
    }

    private function member(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Member): User
    {
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, [
            'role' => $role->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Requires a bound workspace. */
    private function issue(): Issue
    {
        return Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    /** A notification row as Notifier wrote it before recipients were checked. */
    private function legacyRow(User $user, Issue $issue, User $actor): void
    {
        InAppNotification::create([
            'user_id' => $user->id,
            'issue_id' => $issue->id,
            'actor_id' => $actor->id,
            'reason' => NotificationReason::Commented->value,
            'data' => ['excerpt' => 'an update'],
            'created_at' => now(),
        ]);
    }
}
