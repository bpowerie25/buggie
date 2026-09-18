<?php

namespace Tests\Feature;

use App\Actions\TriageReport;
use App\Enums\IssueEventType;
use App\Enums\ReportState;
use App\Enums\WorkspaceRole;
use App\Jobs\ProcessIncomingReport;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Support\Reports\Fingerprint;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportTriageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repeat_occurrences_of_a_known_bug_fold_into_one_issue(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($user, $workspace) {
            $project = Project::factory()->create(['key' => 'WEB']);

            $first = Report::factory()->withError()->create(['project_id' => $project->id]);
            ProcessIncomingReport::dispatchSync($first->id, $workspace->id);

            // Nothing to group with yet, so it waits for a human.
            $this->assertSame(ReportState::New, $first->refresh()->state);
            $this->assertNotNull($first->fingerprint);

            $issue = app(TriageReport::class)->accept($first->refresh(), $user);
            $this->assertSame(1, $issue->occurrence_count);

            // Forty more of the same bug must not become forty more tickets.
            foreach (range(1, 3) as $_) {
                $report = Report::factory()->withError()->create(['project_id' => $project->id]);
                ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

                $this->assertSame(ReportState::Merged, $report->refresh()->state);
                $this->assertSame($issue->id, $report->issue_id);
            }

            $this->assertSame(4, $issue->refresh()->occurrence_count);
            $this->assertSame(1, Issue::count(), 'One bug, one issue.');
            $this->assertCount(
                3,
                $issue->events()->where('type', IssueEventType::Occurrence->value)->get(),
            );
        });
    }

    #[Test]
    public function a_recently_closed_issue_reopens_on_a_new_occurrence(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($user, $workspace) {
            $project = Project::factory()->create();
            $issue = Issue::factory()->inStatus('done')->create([
                'project_id' => $project->id,
                'fingerprint' => Fingerprint::for(
                    ['message' => 'Cannot read properties of null',
                        'stack' => 'at pay (https://acme.test/assets/checkout.js:12:44)'],
                    'https://acme.test/checkout',
                ),
                'closed_at' => now()->subDays(3),
            ]);

            $report = Report::factory()->withError()->create([
                'project_id' => $project->id,
                'environment' => ['url' => 'https://acme.test/checkout'],
            ]);

            ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

            $issue->refresh();

            $this->assertTrue($issue->isOpen(), 'It came back within the window.');
            $this->assertNull($issue->closed_at);
            $this->assertTrue(
                $issue->events->contains(fn ($e) => $e->type === IssueEventType::Reopened),
            );
            unset($user);
        });
    }

    #[Test]
    public function an_issue_closed_long_ago_is_not_quietly_revived(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($workspace) {
            $project = Project::factory()->create();
            $fingerprint = Fingerprint::for(
                ['message' => 'Cannot read properties of null',
                    'stack' => 'at pay (https://acme.test/assets/checkout.js:12:44)'],
                'https://acme.test/checkout',
            );

            $issue = Issue::factory()->inStatus('done')->create([
                'project_id' => $project->id,
                'fingerprint' => $fingerprint,
                'closed_at' => now()->subDays(ProcessIncomingReport::REOPEN_WINDOW_DAYS + 1),
            ]);

            $report = Report::factory()->withError()->create([
                'project_id' => $project->id,
                'environment' => ['url' => 'https://acme.test/checkout'],
            ]);

            ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

            // A regression a month later is a different bug; a human should say so.
            $this->assertFalse($issue->refresh()->isOpen());
            $this->assertSame(ReportState::New, $report->refresh()->state);
        });
    }

    #[Test]
    public function a_report_with_no_error_always_reaches_a_human(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($workspace) {
            $project = Project::factory()->create();

            foreach (range(1, 2) as $_) {
                $report = Report::factory()->create([
                    'project_id' => $project->id,
                    'title' => 'The page feels slow',
                ]);
                ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

                $this->assertSame(ReportState::New, $report->refresh()->state);
                $this->assertNull($report->fingerprint);
            }

            $this->assertSame(2, Report::awaitingTriage()->count());
        });
    }

    #[Test]
    public function accepting_a_report_creates_an_issue_carrying_its_context(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->withError()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            'title' => 'Checkout button does nothing',
            'body' => 'Nothing happens when I click pay.',
        ]));

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, "/inbox/{$report->id}/accept"), [
                'priority' => 4,
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($report) {
            $issue = Issue::firstOrFail();

            $this->assertSame('WEB-1', $issue->key);
            $this->assertSame('Checkout button does nothing', $issue->title);
            $this->assertNotNull($issue->environment, 'Captured context follows the issue.');
            $this->assertStringContainsString('Nothing happens', $issue->description_text);
            $this->assertStringContainsString('Error:', $issue->description_text);

            $this->assertSame(ReportState::Promoted, $report->refresh()->state);
            $this->assertSame($issue->id, $report->issue_id);
        });
    }

    #[Test]
    public function accepting_folds_in_the_rest_of_the_fingerprint_group(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        [$first, $siblings] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            $reports = collect(range(1, 3))->map(
                fn () => Report::factory()->withError()->create(['project_id' => $project->id]),
            );

            // Same bug, so the same fingerprint.
            $fingerprint = Fingerprint::for($reports[0]->error, $reports[0]->environment['url']);
            $reports->each(fn (Report $r) => $r->forceFill(['fingerprint' => $fingerprint])->save());

            return [$reports[0], $reports->slice(1)->pluck('id')->all()];
        });

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, "/inbox/{$first->id}/accept"), [
                'also' => $siblings,
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () {
            $this->assertSame(1, Issue::count());
            $this->assertSame(3, Issue::firstOrFail()->occurrence_count);
            $this->assertSame(0, Report::awaitingTriage()->count(), 'The inbox is empty.');
        });
    }

    #[Test]
    public function a_crafted_sibling_list_cannot_reach_other_reports(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        [$report, $unrelated] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [
                Report::factory()->withError()->create(['project_id' => $project->id]),
                // Different bug entirely, and not in the group.
                Report::factory()->create(['project_id' => $project->id, 'title' => 'Unrelated']),
            ];
        });

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, "/inbox/{$report->id}/accept"), [
                'also' => [$unrelated->id],
            ])
            ->assertRedirect();

        // Siblings are re-fetched and filtered by fingerprint, never trusted from input.
        $this->assertSame(
            ReportState::New,
            app(Tenancy::class)->run($workspace, fn () => $unrelated->refresh()->state),
        );
    }

    #[Test]
    public function a_client_cannot_see_or_triage_the_inbox(): void
    {
        [$workspace, $client] = $this->workspaceWithMember(WorkspaceRole::Client, 'acme');

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->create([
            'project_id' => Project::factory()->create()->id,
        ]));

        // Reports hold raw, unreviewed context — screenshots of whatever was on screen.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/inbox'))
            ->assertForbidden();

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/inbox/{$report->id}/accept"))
            ->assertForbidden();
    }

    #[Test]
    public function dismissing_clears_the_report_without_creating_anything(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->create([
            'project_id' => Project::factory()->create()->id,
        ]));

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, "/inbox/{$report->id}/dismiss"), [
                'state' => ReportState::Spam->value,
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($report) {
            $this->assertSame(ReportState::Spam, $report->refresh()->state);
            $this->assertSame(0, Issue::count());
            $this->assertSame(0, Report::awaitingTriage()->count());
        });
    }

    #[Test]
    public function reports_from_another_workspace_are_unreachable(): void
    {
        [$acme, $acmeUser] = $this->workspaceWithMember(WorkspaceRole::Owner, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Owner, 'globex');

        $theirs = app(Tenancy::class)->run($globex, fn () => Report::factory()->create([
            'project_id' => Project::factory()->create()->id,
        ]));

        $this->actingAs($acmeUser)
            ->post($this->workspaceUrl($acme, "/inbox/{$theirs->id}/accept"))
            ->assertNotFound();
    }
}
