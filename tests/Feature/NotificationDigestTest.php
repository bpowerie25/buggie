<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\UpdateIssue;
use App\Enums\NotificationReason;
use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IssueDigest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationDigestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_burst_of_activity_becomes_one_message(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');

        $watcher = User::factory()->create();
        $workspace->members()->attach($watcher->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
            $issue->watch($watcher, WatchReason::Manual);

            // Nine things happen in quick succession.
            foreach (range(1, 3) as $i) {
                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc("comment {$i}"),
                    'is_internal' => true,
                ], $actor);
            }

            $inProgress = $issue->project->statuses()->where('category', 'started')->first();
            app(UpdateIssue::class)->handle($issue, ['status_id' => $inProgress->id], $actor);
        });

        $this->assertSame(4, PendingNotification::withoutGlobalScopes()->count());

        // Nothing goes out while the issue is still busy.
        $this->artisan('notifications:flush')->assertSuccessful();
        Notification::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('notifications:flush')->assertSuccessful();

        Notification::assertSentToTimes($watcher, IssueDigest::class, 1);
        $this->assertSame(0, PendingNotification::withoutGlobalScopes()->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function the_window_is_measured_from_the_last_entry(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');

        $watcher = User::factory()->create();
        $workspace->members()->attach($watcher->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        $issue = app(Tenancy::class)->run($workspace, function () use ($watcher) {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
            $issue->watch($watcher, WatchReason::Manual);

            return $issue;
        });

        app(Tenancy::class)->run($workspace, fn () => app(AddComment::class)->handle($issue, [
            'body' => $this->doc('first'), 'is_internal' => true,
        ], $actor));

        // Four minutes later the conversation continues, which resets the quiet period.
        Carbon::setTestNow(now()->addMinutes(4));
        app(Tenancy::class)->run($workspace, fn () => app(AddComment::class)->handle($issue, [
            'body' => $this->doc('second'), 'is_internal' => true,
        ], $actor));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('notifications:flush');
        Notification::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(4));
        $this->artisan('notifications:flush');
        Notification::assertSentToTimes($watcher, IssueDigest::class, 1);

        Carbon::setTestNow();
    }

    #[Test]
    public function nobody_is_told_about_their_own_actions(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($actor) {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
            $issue->watch($actor, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('talking to myself'), 'is_internal' => true,
            ], $actor);
        });

        $this->assertSame(0, PendingNotification::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_client_watcher_is_never_told_about_internal_activity(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($staff, $client) {
            $project = Project::factory()->create();
            // A client who can see the issue: only such a client is ever emailed about
            // it, so only such a client makes this test mean anything.
            $project->clients()->attach($client->id, ['role' => 'client_manager']);

            $issue = Issue::factory()->clientVisible()->create(['project_id' => $project->id]);
            $issue->watch($client, WatchReason::Reported);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Dave broke it again'), 'is_internal' => true,
            ], $staff);
        });

        // An email is a way for an internal note to escape, so the check is here too.
        $this->assertSame(0, PendingNotification::withoutGlobalScopes()
            ->where('user_id', $client->id)->count());

        app(Tenancy::class)->run($workspace, function () use ($staff, $client) {
            $issue = Issue::firstOrFail();

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('We are on it'), 'is_internal' => false,
            ], $staff);

            $this->assertSame(1, PendingNotification::where('user_id', $client->id)->count());
            unset($client);
        });
    }

    #[Test]
    public function turning_a_reason_off_silences_it(): void
    {
        Notification::fake();
        [$workspace, $actor] = $this->workspaceWithMember(slug: 'acme');

        $watcher = User::factory()->create([
            'notification_settings' => [NotificationReason::Commented->value => false],
        ]);
        $workspace->members()->attach($watcher->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($actor, $watcher) {
            $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
            $issue->watch($watcher, WatchReason::Manual);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('hello'), 'is_internal' => true,
            ], $actor);

            // Assignment is still on, so it still records.
            app(UpdateIssue::class)->handle($issue, ['assignee_id' => $watcher->id], $actor);
        });

        $reasons = PendingNotification::withoutGlobalScopes()
            ->where('user_id', $watcher->id)->pluck('reason')->all();

        $this->assertSame([NotificationReason::Assigned->value], $reasons);
    }

    #[Test]
    public function digests_do_not_cross_workspaces(): void
    {
        Notification::fake();
        [$acme, $acmeUser] = $this->workspaceWithMember(slug: 'acme');
        [$globex, $globexUser] = $this->workspaceWithMember(slug: 'globex');

        foreach ([[$acme, $acmeUser], [$globex, $globexUser]] as [$workspace, $owner]) {
            $other = User::factory()->create();
            $workspace->members()->attach($other->id, [
                'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
            ]);

            app(Tenancy::class)->run($workspace, function () use ($owner, $other) {
                $issue = Issue::factory()->create(['project_id' => Project::factory()->create()->id]);
                $issue->watch($other, WatchReason::Manual);

                app(AddComment::class)->handle($issue, [
                    'body' => $this->doc('hello'), 'is_internal' => true,
                ], $owner);
            });
        }

        $this->assertSame(2, PendingNotification::withoutGlobalScopes()->count());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('notifications:flush')->expectsOutputToContain('Sent 2 digests.');

        Carbon::setTestNow();
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
}
