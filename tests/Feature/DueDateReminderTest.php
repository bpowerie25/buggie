<?php

namespace Tests\Feature;

use App\Enums\NotificationReason;
use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\IssueDigest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Due dates are only worth having if something chases them, and chasing is only
 * worth having if it stops. Most of what follows is about the second half.
 *
 * Every negative here is paired with a positive control in the same test: "the closed
 * issue was not chased" passes just as well when nothing is chased at all, and that is
 * the version of this suite that would survive deleting the feature.
 */
class DueDateReminderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_issue_due_tomorrow_tells_the_assignee_and_the_watchers(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);
        $watcher = $this->staffMember($workspace);
        $stranger = $this->staffMember($workspace);

        $issue = $this->issue($workspace, ['due_on' => '2026-10-16', 'assignee_id' => $assignee->id]);
        $this->inWorkspace($workspace, fn () => $issue->watch($watcher, WatchReason::Manual));

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([-1], $this->daysRecordedFor($assignee));
        $this->assertSame([-1], $this->daysRecordedFor($watcher));

        // Nobody in the workspace who is neither holding nor watching it.
        $this->assertSame([], $this->daysRecordedFor($stranger));

        $row = $this->pending()->where('user_id', $assignee->id)->first();
        $this->assertSame(NotificationReason::DueDate->value, $row->reason);
        $this->assertSame('2026-10-16', $row->data['due_on']);

        // The command runs outside any tenant context, so the stamping the trait does
        // on insert is exactly the thing most likely to be wrong.
        $this->assertSame($workspace->id, $row->workspace_id);
        $this->assertNull($row->actor_id, 'Nobody caused a date to arrive.');
    }

    #[Test]
    public function an_overdue_issue_is_chased_and_says_how_late_it_is(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $this->issue($workspace, ['due_on' => '2026-10-14', 'assignee_id' => $assignee->id]);

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([1], $this->daysRecordedFor($assignee));
    }

    /**
     * The off-by-one that this feature invites: comparing a date to a moment.
     *
     * The same issue is chased at one second to midnight on the day before, at the
     * first second of the due day and at the last second of it, and on the day after.
     * Four runs, three reminders, one per calendar day.
     */
    #[Test]
    public function the_boundary_is_the_date_not_the_moment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $this->issue($workspace, ['due_on' => '2026-10-16', 'assignee_id' => $assignee->id]);

        foreach (['2026-10-15 23:59:59', '2026-10-16 00:00:00', '2026-10-16 23:59:59', '2026-10-17 12:00:00'] as $moment) {
            $this->travelTo(Carbon::parse($moment));
            $this->artisan('issues:chase-due')->assertSuccessful();
        }

        $this->assertSame(
            [-1, 0, 1],
            $this->daysRecordedFor($assignee),
            'Due tomorrow, due today and one day late — and the due day only once, '
            .'whichever end of it the command runs at.',
        );
    }

    #[Test]
    public function running_it_twice_in_one_day_does_not_notify_twice(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 06:40'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $this->issue($workspace, ['due_on' => '2026-10-15', 'assignee_id' => $assignee->id]);

        // A scheduled run, a retry after a wobble, and somebody running it by hand.
        $this->artisan('issues:chase-due')->assertSuccessful();
        $this->artisan('issues:chase-due')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-15 18:00'));
        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([0], $this->daysRecordedFor($assignee));

        // Tomorrow is the next rung, and it is not silenced by yesterday's stamp.
        $this->travelTo(Carbon::parse('2026-10-16 06:40'));
        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([0, 1], $this->daysRecordedFor($assignee));
    }

    /**
     * The headline: an issue overdue for three weeks does not produce twenty-one
     * emails. It produces six, and they get further apart.
     */
    #[Test]
    public function three_weeks_overdue_is_six_reminders_not_twenty_one(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 06:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $this->issue($workspace, ['due_on' => '2026-10-15', 'assignee_id' => $assignee->id]);

        // Twice a day, every day, for three weeks: the scheduled run and somebody
        // running it by hand. Both halves of the policy are under test here — the
        // ladder decides which days, the stamp decides how often within one.
        for ($day = 0; $day <= 21; $day++) {
            foreach (['06:40', '18:05'] as $time) {
                $this->travelTo(Carbon::parse("2026-10-15 {$time}")->addDays($day));
                $this->artisan('issues:chase-due')->assertSuccessful();
            }
        }

        $this->assertSame([0, 1, 3, 7, 14, 21], $this->daysRecordedFor($assignee));
    }

    #[Test]
    public function a_closed_or_cancelled_issue_is_never_chased(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $project = $this->inWorkspace($workspace, fn () => Project::factory()->create());

        $open = $this->issue($workspace, [
            'due_on' => '2026-10-14', 'assignee_id' => $assignee->id, 'project_id' => $project->id,
        ]);

        // Statuses are renameable per project, so these are made by category. The
        // project's "Done" could be called "Shipped" and nothing here would change.
        $done = $this->issue($workspace, [
            'due_on' => '2026-10-14', 'assignee_id' => $assignee->id, 'project_id' => $project->id,
        ], category: 'done');

        $cancelled = $this->issue($workspace, [
            'due_on' => '2026-10-14', 'assignee_id' => $assignee->id, 'project_id' => $project->id,
        ], category: 'canceled');

        $this->artisan('issues:chase-due')->assertSuccessful();

        // The positive control: the open issue, identical in every other way, IS
        // chased. Without this the assertions below pass on a broken command.
        $this->assertSame(
            [$open->id],
            $this->pending()->where('user_id', $assignee->id)->pluck('issue_id')->all(),
        );

        // And nothing was quietly marked as chased either, so they will not be
        // skipped if somebody reopens them.
        $this->assertNull($done->fresh()->due_reminded_on);
        $this->assertNull($cancelled->fresh()->due_reminded_on);
    }

    /**
     * The leak this feature could cause. A client is told about an issue only when
     * they could have opened it themselves: client-visible, in a project they hold.
     */
    #[Test]
    public function a_client_is_not_told_about_an_issue_they_cannot_see(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $client = $this->clientMember($workspace);

        [$shared, $internal, $elsewhere] = $this->inWorkspace($workspace, function () use ($client, $staff) {
            $theirs = Project::factory()->create(['name' => 'Northwind Site', 'key' => 'NW']);
            $other = Project::factory()->create(['name' => 'Globex Portal', 'key' => 'GX']);

            $theirs->clients()->attach($client->id, ['role' => 'client']);

            $shared = Issue::factory()->clientVisible()->create([
                'project_id' => $theirs->id, 'due_on' => '2026-10-14',
            ]);

            // Same project, but nobody decided to share this one.
            $internal = Issue::factory()->create([
                'project_id' => $theirs->id, 'due_on' => '2026-10-14', 'assignee_id' => $staff->id,
            ]);

            // Shared, but in another customer's project. Even the name is a leak.
            $elsewhere = Issue::factory()->clientVisible()->create([
                'project_id' => $other->id, 'due_on' => '2026-10-14',
            ]);

            foreach ([$shared, $internal, $elsewhere] as $issue) {
                $issue->watch($client, WatchReason::Manual);
            }

            return [$shared, $internal, $elsewhere];
        });

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame(
            [$shared->id],
            $this->pending()->where('user_id', $client->id)->pluck('issue_id')->all(),
            'A client watching a client-visible issue in a project they hold is fine. '
            .'Anything else is a leak.',
        );

        // The control that makes the line above mean something: the internal issue
        // was chased, just not to the client.
        $this->assertSame(
            [$internal->id],
            $this->pending()->where('user_id', $staff->id)->pluck('issue_id')->all(),
        );

        unset($elsewhere);
    }

    #[Test]
    public function turning_due_dates_off_silences_them(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $quiet = $this->staffMember($workspace);
        $quiet->forceFill(['notification_settings' => [
            NotificationReason::DueDate->value => false,
        ]])->save();

        $loud = $this->staffMember($workspace);

        $issue = $this->issue($workspace, ['due_on' => '2026-10-14']);

        $this->inWorkspace($workspace, function () use ($issue, $quiet, $loud) {
            $issue->watch($quiet, WatchReason::Manual);
            $issue->watch($loud, WatchReason::Manual);
        });

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([], $this->daysRecordedFor($quiet));
        $this->assertSame([1], $this->daysRecordedFor($loud), 'Switching one person off is not switching everyone off.');
    }

    #[Test]
    public function an_archived_projects_deadlines_are_left_alone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        [$live, $shelved] = $this->inWorkspace($workspace, function () use ($assignee) {
            $live = Issue::factory()->create([
                'project_id' => Project::factory()->create()->id,
                'due_on' => '2026-10-14',
                'assignee_id' => $assignee->id,
            ]);

            $shelved = Issue::factory()->create([
                'project_id' => Project::factory()->archived()->create()->id,
                'due_on' => '2026-10-14',
                'assignee_id' => $assignee->id,
            ]);

            return [$live, $shelved];
        });

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame(
            [$live->id],
            $this->pending()->where('user_id', $assignee->id)->pluck('issue_id')->all(),
            'A project somebody archived stopped having deadlines when they archived it.',
        );

        unset($shelved);
    }

    #[Test]
    public function chasing_an_issue_is_not_activity_on_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $issue = $this->issue($workspace, ['due_on' => '2026-10-14', 'assignee_id' => $assignee->id]);
        $before = $issue->fresh()->updated_at;

        // Three days late: a rung, so there is something to record.
        $this->travelTo(Carbon::parse('2026-10-17 07:00'));
        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([3], $this->daysRecordedFor($assignee));

        // A reminder is bookkeeping. If it moved updated_at the issue would climb to
        // the top of the list and read as though somebody had touched the work.
        $this->assertEquals($before, $issue->fresh()->updated_at);
        $this->assertSame('2026-10-17', $issue->fresh()->due_reminded_on->toDateString());
    }

    #[Test]
    public function a_dry_run_reports_without_recording_or_stamping(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $issue = $this->issue($workspace, ['due_on' => '2026-10-14', 'assignee_id' => $assignee->id]);

        $this->artisan('issues:chase-due --dry-run')->assertSuccessful();

        $this->assertSame([], $this->daysRecordedFor($assignee));
        $this->assertNull($issue->fresh()->due_reminded_on);

        // The control: the same run without the flag does the work, so the emptiness
        // above is the flag and not a command that never matched anything.
        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->assertSame([1], $this->daysRecordedFor($assignee));
    }

    #[Test]
    public function the_reminder_arrives_as_a_digest_that_says_what_is_late(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-15 07:00'));

        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $assignee = $this->staffMember($workspace);

        $this->issue($workspace, ['due_on' => '2026-10-14', 'assignee_id' => $assignee->id]);

        $this->artisan('issues:chase-due')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-10-15 07:10'));
        $this->artisan('notifications:flush')->assertSuccessful();

        Notification::assertSentTo(
            $assignee,
            IssueDigest::class,
            fn (IssueDigest $digest) => in_array(
                'This was due yesterday.',
                $digest->toArray($assignee)['lines'],
                true,
            ),
        );
    }

    // --- scaffolding ----------------------------------------------------------

    private function staffMember(Workspace $workspace): User
    {
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function clientMember(Workspace $workspace): User
    {
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function issue(Workspace $workspace, array $attributes, ?string $category = null): Issue
    {
        return $this->inWorkspace($workspace, function () use ($attributes, $category) {
            $factory = Issue::factory();

            if ($category !== null) {
                $factory = $factory->inStatus($category);
            }

            return $factory->create([
                'project_id' => $attributes['project_id'] ?? Project::factory()->create()->id,
                ...$attributes,
            ]);
        });
    }

    private function inWorkspace(Workspace $workspace, \Closure $callback): mixed
    {
        return app(Tenancy::class)->run($workspace, $callback);
    }

    /** @return Builder<PendingNotification> */
    private function pending(): Builder
    {
        return PendingNotification::query()->withoutGlobalScopes()->orderBy('id');
    }

    /**
     * Every due-date reminder recorded for somebody, as the day offsets they carry.
     *
     * @return array<int, int>
     */
    private function daysRecordedFor(User $user): array
    {
        return $this->pending()
            ->where('user_id', $user->id)
            ->where('reason', NotificationReason::DueDate->value)
            ->get()
            ->map(fn (PendingNotification $row) => (int) $row->data['days'])
            ->all();
    }
}
