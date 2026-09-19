<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Issue} */
    private function issue(WorkspaceRole $role = WorkspaceRole::Owner): array
    {
        [$workspace, $user] = $this->workspaceWithMember($role, 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Broken'], $user);
        });

        return [$workspace, $user, $issue];
    }

    #[Test]
    public function time_can_be_logged_against_an_issue(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/time"), [
                'duration' => '1h 30m',
                'spent_on' => now()->toDateString(),
                'note' => 'Traced it to the payment form',
            ])
            ->assertRedirect();

        $entry = TimeEntry::withoutGlobalScopes()->first();

        $this->assertSame(90, $entry->minutes);
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertTrue($entry->billable, 'Billable is the default for an agency.');
    }

    #[Test]
    public function an_unparseable_duration_says_why(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/time"), [
                'duration' => 'ages',
                'spent_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('duration');

        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->count());
    }

    #[Test]
    public function time_cannot_be_logged_in_the_future(): void
    {
        // Work that has not happened yet is a plan, not a timesheet entry, and a
        // future date quietly falls outside every month-end report.
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/time"), [
                'duration' => '1h',
                'spent_on' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('spent_on');
    }

    #[Test]
    public function an_estimate_can_be_set_and_cleared(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}/estimate"), ['estimate' => '2h'])
            ->assertRedirect();

        $this->assertSame(120, $issue->refresh()->estimate_minutes);

        // Cleared, not zeroed: "nobody estimated this" is a different claim from
        // "this will take no time".
        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}/estimate"), ['estimate' => ''])
            ->assertRedirect();

        $this->assertNull($issue->refresh()->estimate_minutes);
    }

    // ------------------------------------------------------------------ leaking

    #[Test]
    public function a_client_never_sees_time_on_an_issue(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'visibility' => 'client',
            ], $staff);

            TimeEntry::create([
                'issue_id' => $issue->id,
                'user_id' => $staff->id,
                'minutes' => 480,
                'spent_on' => now()->toDateString(),
                'note' => 'SECRET-TIME-NOTE',
            ]);

            $issue->forceFill(['estimate_minutes' => 60])->save();

            return [$project, $issue];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $html = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('SECRET-TIME-NOTE', $html);
        $this->assertStringNotContainsString('8h', $html, 'The total leaked.');

        // Positive control: staff on the same issue do see it, so this is not passing
        // because the page renders nothing.
        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertSee('SECRET-TIME-NOTE');
    }

    #[Test]
    public function a_client_cannot_log_time(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            return [$project, app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'visibility' => 'client',
            ], $staff)];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/time"), [
                'duration' => '1h', 'spent_on' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_reach_the_time_report(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/time'))
            ->assertForbidden();

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/time/export'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ ownership

    #[Test]
    public function a_member_can_remove_their_own_entry_but_not_someone_elses(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $member = User::factory()->create();
        $workspace->members()->attach($member->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        [$mine, $theirs] = app(Tenancy::class)->run($workspace, fn () => [
            TimeEntry::create([
                'issue_id' => $issue->id, 'user_id' => $member->id,
                'minutes' => 60, 'spent_on' => now()->toDateString(),
            ]),
            TimeEntry::create([
                'issue_id' => $issue->id, 'user_id' => $owner->id,
                'minutes' => 60, 'spent_on' => now()->toDateString(),
            ]),
        ]);

        $this->actingAs($member)
            ->delete($this->workspaceUrl($workspace, "/time/{$mine->id}"))
            ->assertRedirect();

        // Deleting somebody's logged hours changes what they are paid for.
        $this->actingAs($member)
            ->delete($this->workspaceUrl($workspace, "/time/{$theirs->id}"))
            ->assertForbidden();

        $this->assertSame(1, TimeEntry::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_owner_can_remove_anyones_entry(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        $member = User::factory()->create();
        $workspace->members()->attach($member->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        $entry = app(Tenancy::class)->run($workspace, fn () => TimeEntry::create([
            'issue_id' => $issue->id, 'user_id' => $member->id,
            'minutes' => 60, 'spent_on' => now()->toDateString(),
        ]));

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/time/{$entry->id}"))
            ->assertRedirect();

        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->count());
    }

    #[Test]
    public function hours_survive_the_person_who_worked_them(): void
    {
        // They were still worked, and may already be on an invoice. Cascading would
        // silently change last quarter's numbers.
        [$workspace, $owner, $issue] = $this->issue();

        $member = User::factory()->create();
        $workspace->members()->attach($member->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, fn () => TimeEntry::create([
            'issue_id' => $issue->id, 'user_id' => $member->id,
            'minutes' => 120, 'spent_on' => now()->toDateString(),
        ]));

        $member->delete();

        $entry = TimeEntry::withoutGlobalScopes()->first();

        $this->assertNotNull($entry, 'The entry went with the account.');
        $this->assertNull($entry->user_id);
        $this->assertSame(120, $entry->minutes);
    }

    // ------------------------------------------------------------------- report

    #[Test]
    public function the_report_totals_every_matching_entry_not_just_the_listed_ones(): void
    {
        // The list is capped; the total is not. A total quietly smaller than the
        // truth is the worst possible bug on a page somebody invoices from.
        [$workspace, $owner, $issue] = $this->issue();

        app(Tenancy::class)->run($workspace, function () use ($issue, $owner) {
            foreach (range(1, 3) as $i) {
                TimeEntry::create([
                    'issue_id' => $issue->id, 'user_id' => $owner->id,
                    'minutes' => 60, 'spent_on' => now()->toDateString(),
                    'billable' => $i !== 3,
                ]);
            }
        });

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/time'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.minutes', 180)
                ->where('totals.duration', '3h')
                ->where('totals.billable_minutes', 120)
                ->where('totals.entries', 3));
    }

    #[Test]
    public function the_report_can_be_narrowed_to_a_date_range(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        app(Tenancy::class)->run($workspace, function () use ($issue, $owner) {
            TimeEntry::create([
                'issue_id' => $issue->id, 'user_id' => $owner->id,
                'minutes' => 60, 'spent_on' => now()->subMonths(2)->toDateString(),
            ]);
            TimeEntry::create([
                'issue_id' => $issue->id, 'user_id' => $owner->id,
                'minutes' => 30, 'spent_on' => now()->toDateString(),
            ]);
        });

        // The default is this calendar month, which excludes the older one.
        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/time'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.minutes', 30));

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/time?from='.now()->subMonths(3)->toDateString()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.minutes', 90));
    }

    #[Test]
    public function the_export_gives_minutes_and_hours(): void
    {
        [$workspace, $owner, $issue] = $this->issue();

        app(Tenancy::class)->run($workspace, fn () => TimeEntry::create([
            'issue_id' => $issue->id, 'user_id' => $owner->id,
            'minutes' => 20, 'spent_on' => now()->toDateString(),
            'note' => '=1+1',
        ]));

        $csv = $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/time/export'))
            ->streamedContent();

        $this->assertStringContainsString('minutes,hours', $csv);
        // Twenty minutes is 0.33 hours. Stored as minutes precisely so the column of
        // them adds up.
        $this->assertStringContainsString('20,0.33', $csv);
        $this->assertStringContainsString("'=1+1", $csv, 'A note is a formula waiting to happen.');
    }

    #[Test]
    public function time_is_scoped_to_its_workspace(): void
    {
        [$acme, $owner, $issue] = $this->issue();

        app(Tenancy::class)->run($acme, fn () => TimeEntry::create([
            'issue_id' => $issue->id, 'user_id' => $owner->id,
            'minutes' => 60, 'spent_on' => now()->toDateString(),
        ]));

        [$other, $stranger] = $this->workspaceWithMember(WorkspaceRole::Owner, 'other');

        $this->actingAs($stranger)
            ->get($this->workspaceUrl($other, '/time'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.minutes', 0));
    }
}
