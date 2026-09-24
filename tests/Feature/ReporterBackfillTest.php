<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\TriageReport;
use App\Enums\ProjectRole;
use App\Enums\ReportState;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Reports\ReporterLink;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Widget issues accepted before a workspace trusted typed emails — or before the
 * identity was recorded at all — linked afterwards to the clients who reported them,
 * so a client on "own issues only" sees what they sent through the widget.
 */
class ReporterBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));

        $this->ann = User::factory()->create(['email' => 'ann@kennco.test', 'name' => 'Ann']);
        $this->workspace->members()->attach($this->ann->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($this->ann->id, ['role' => ProjectRole::Client->value]);
    }

    #[Test]
    public function trusting_typed_emails_links_what_was_already_accepted(): void
    {
        $issue = $this->acceptedWidgetIssue('ANN@kennco.test');

        $this->assertNotSame($this->ann->id, $issue->fresh()->reporter_id);
        $this->actingAs($this->ann)->get($this->issueUrl($issue))->assertNotFound();

        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, '/settings/workspace'), ['name' => 'Matrix', 'trust_unverified_emails' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Workspace updated. 1 existing widget issue was linked to the client who reported it.');

        $this->assertSame($this->ann->id, $issue->fresh()->reporter_id);
        $this->actingAs($this->ann)->get($this->issueUrl($issue))->assertOk();
    }

    #[Test]
    public function an_issue_accepted_before_identities_were_recorded_is_found_through_its_report(): void
    {
        $issue = $this->acceptedWidgetIssue('ann@kennco.test');

        // As an install from before the identity was copied onto the issue.
        DB::table('issues')->where('id', $issue->id)->update(['reporter_identity' => null, 'reporter_email' => null, 'reporter_name' => null]);
        DB::table('reports')->where('issue_id', $issue->id)->update(['reporter_identity' => null]);

        $this->trust();
        $result = ReporterLink::backfill($this->workspace);

        $this->assertSame(1, $result['linked']);
        $issue->refresh();
        $this->assertSame($this->ann->id, $issue->reporter_id);
        $this->assertSame('ann@kennco.test', $issue->reporter_email);
        $this->assertSame('email_unverified', $issue->reporter_identity->value);
    }

    #[Test]
    public function a_merged_duplicate_never_takes_over_somebody_elses_issue(): void
    {
        $staffIssue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => 'Raised by the team'], $this->owner));

        $this->tenant(fn () => Report::factory()->create([
            'project_id' => $this->project->id,
            'reporter_email' => 'ann@kennco.test',
            'state' => ReportState::Merged->value,
            'issue_id' => $staffIssue->id,
        ]));

        $this->trust();

        $this->assertSame(0, ReporterLink::backfill($this->workspace)['linked']);
        $this->assertSame($this->owner->id, $staffIssue->fresh()->reporter_id);
    }

    #[Test]
    public function only_a_client_on_the_project_is_ever_linked_and_running_twice_changes_nothing(): void
    {
        $staffEmail = $this->acceptedWidgetIssue($this->owner->email);
        $stranger = $this->acceptedWidgetIssue('nobody@elsewhere.test');

        $offProject = User::factory()->create(['email' => 'bob@kennco.test']);
        $this->workspace->members()->attach($offProject->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $notTheirs = $this->acceptedWidgetIssue('bob@kennco.test');

        $ann = $this->acceptedWidgetIssue('ann@kennco.test');

        $this->trust();

        $this->assertSame([$ann->key], ReporterLink::backfill($this->workspace)['keys']);
        $this->assertSame(0, ReporterLink::backfill($this->workspace)['linked'], 'A second run linked again.');

        foreach ([$staffEmail, $stranger, $notTheirs] as $issue) {
            $this->assertSame($this->owner->id, $issue->fresh()->reporter_id);
        }
    }

    #[Test]
    public function without_trust_only_verified_reports_are_linked(): void
    {
        $typed = $this->acceptedWidgetIssue('ann@kennco.test');
        $verified = $this->acceptedWidgetIssue('ann@kennco.test', 'verified', link: false);

        $result = ReporterLink::backfill($this->workspace);

        $this->assertSame([$verified->key], $result['keys']);
        $this->assertNotSame($this->ann->id, $typed->fresh()->reporter_id);
    }

    #[Test]
    public function the_command_can_say_first_what_it_would_do(): void
    {
        $issue = $this->acceptedWidgetIssue('ann@kennco.test');
        $this->trust();

        $this->artisan('buggie:link-widget-reporters', ['--dry-run' => true])
            ->expectsOutputToContain("1 of 1 widget issues would be linked — {$issue->key}")
            ->assertSuccessful();

        $this->assertNotSame($this->ann->id, $issue->fresh()->reporter_id);

        $this->artisan('buggie:link-widget-reporters', ['--workspace' => 'matrix'])->expectsOutputToContain('1 linked.')->assertSuccessful();

        $this->assertSame($this->ann->id, $issue->fresh()->reporter_id);
    }

    /**
     * A report accepted through Triage as it happens today, with trust off so the
     * accept itself does not link — the state an install is in before turning it on.
     */
    private function acceptedWidgetIssue(string $email, string $identity = 'email_unverified', bool $link = true): Issue
    {
        $report = $this->tenant(fn () => Report::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'From the widget '.uniqid(),
            'reporter_email' => $email,
            'reporter_identity' => $identity,
        ]));

        $issue = $this->tenant(fn () => app(TriageReport::class)->accept($report, $this->owner));

        // A verified one is linked on acceptance already; undo that to test the
        // backfill finding it.
        if (! $link || $identity === 'verified') {
            DB::table('issues')->where('id', $issue->id)->update(['reporter_id' => $this->owner->id]);
        }

        return $issue->fresh();
    }

    private function trust(): void
    {
        $this->workspace->forceFill(['settings' => [ReporterLink::TRUST_UNVERIFIED => true]])->save();
        $this->workspace->refresh();
    }

    private function issueUrl(Issue $issue): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$issue->key}");
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
