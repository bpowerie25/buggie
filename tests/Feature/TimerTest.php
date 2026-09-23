<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\RunningTimer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The optional clock. Everything it produces is an ordinary time entry.
 */
class TimerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Issue} */
    private function issue(string $slug = 'acme'): array
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: $slug);

        $issue = app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Broken'], $user);
        });

        return [$workspace, $user, $issue];
    }

    #[Test]
    public function starting_and_stopping_writes_an_ordinary_time_entry(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->assertSame(1, RunningTimer::withoutGlobalScopes()->count());

        $this->travel(25)->minutes();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/timer/stop'), ['note' => 'Traced it'])
            ->assertRedirect();

        $entry = TimeEntry::withoutGlobalScopes()->sole();

        $this->assertSame(25, $entry->minutes);
        $this->assertSame('Traced it', $entry->note);
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame(0, RunningTimer::withoutGlobalScopes()->count(), 'The clock kept running.');
    }

    #[Test]
    public function a_timer_stopped_within_a_minute_still_records_one(): void
    {
        // Flooring to zero would silently log nothing and look like a bug in the
        // button rather than in the arithmetic.
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/timer/stop'))
            ->assertRedirect();

        $this->assertSame(1, TimeEntry::withoutGlobalScopes()->sole()->minutes);
    }

    #[Test]
    public function starting_a_second_timer_logs_the_first(): void
    {
        // Whatever was running was being worked on until that moment. Discarding it
        // silently would lose real work; refusing would just be annoying.
        [$workspace, $owner, $first] = $this->issue();

        $second = app(Tenancy::class)->run($workspace, fn () => app(CreateIssue::class)->handle(
            $first->project,
            ['title' => 'Something else'],
            $owner,
        ));

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$first->key}/timer"))
            ->assertRedirect();

        $this->travel(40)->minutes();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$second->key}/timer"))
            ->assertRedirect();

        $entry = TimeEntry::withoutGlobalScopes()->sole();

        $this->assertSame($first->id, $entry->issue_id, 'The first timer was not logged.');
        $this->assertSame(40, $entry->minutes);

        // And exactly one clock is still running, on the new issue.
        $running = RunningTimer::withoutGlobalScopes()->sole();
        $this->assertSame($second->id, $running->issue_id);
    }

    #[Test]
    public function a_forgotten_timer_is_not_guessed_at(): void
    {
        /*
         * Nobody worked nineteen hours straight. Writing that down is worse than
         * asking, because it goes onto an invoice and nobody notices until a client
         * does.
         */
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->travel(19)->hours();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/timer/stop'))
            ->assertRedirect();

        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->count(), 'It guessed.');
        $this->assertSame(0, RunningTimer::withoutGlobalScopes()->count(), 'The clock kept running.');
        $this->assertStringContainsString('by hand', session('error'));
    }

    #[Test]
    public function a_long_but_plausible_session_is_logged(): void
    {
        // The paired control: a rule that refused everything long would pass the test
        // above while making the timer useless.
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->travel(6)->hours();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/timer/stop'))
            ->assertRedirect();

        $this->assertSame(360, TimeEntry::withoutGlobalScopes()->sole()->minutes);
    }

    #[Test]
    public function a_timer_can_be_thrown_away(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->travel(30)->minutes();

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, '/timer'))
            ->assertRedirect();

        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, RunningTimer::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_running_timer_follows_you_around(): void
    {
        // The whole failure mode is forgetting it, so it is on every page rather than
        // only on the issue being timed.
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('timer.issue.key', $issue->key));

        // And says nothing once it is stopped.
        $this->actingAs($owner)->post($this->workspaceUrl($workspace, '/timer/stop'));

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('timer', null));
    }

    #[Test]
    public function a_client_cannot_run_a_timer(): void
    {
        // Time is staff-only, and the clock is time.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return [$project, app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'visibility' => 'client',
            ], $staff)];
        });

        $project->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertForbidden();

        // The control: staff on the same issue can.
        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/timer"))
            ->assertRedirect();
    }

    #[Test]
    public function one_person_has_one_clock_even_across_workspaces(): void
    {
        /*
         * You can only be doing one thing at a time, and a timer running in a
         * workspace you cannot see from here is exactly the one left going over a
         * weekend. Starting elsewhere logs it rather than leaving two.
         */
        [$acme, $owner, $here] = $this->issue('acme');
        [$other] = $this->workspaceWithMember(WorkspaceRole::Owner, 'other');

        $other->members()->attach($owner->id, [
            'role' => WorkspaceRole::Owner->value, 'joined_at' => now(),
        ]);

        $there = app(Tenancy::class)->run($other, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Elsewhere']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Other work'], $owner);
        });

        $this->actingAs($owner)->post($this->workspaceUrl($acme, "/issues/{$here->key}/timer"));

        $this->travel(15)->minutes();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($other, "/issues/{$there->key}/timer"))
            ->assertRedirect();

        $this->assertSame(1, RunningTimer::withoutGlobalScopes()->count(), 'Two clocks were running.');

        // The first one's time went to the first workspace, not the one that stopped it.
        $entry = TimeEntry::withoutGlobalScopes()->sole();
        $this->assertSame($acme->id, $entry->workspace_id);
        $this->assertSame(15, $entry->minutes);
    }
}
