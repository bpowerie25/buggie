<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\RelateIssues;
use App\Enums\ProjectRole;
use App\Enums\RelationType;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Issues\Blockage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporting on blockers: what is blocked, what is in the way, and which of those are
 * costing days — as filters, as badges, and on Insights.
 */
class BlockersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));
    }

    #[Test]
    public function blocked_and_blocking_count_only_open_work(): void
    {
        [$a, $b] = [$this->issue('A'), $this->issue('B')];
        [$c, $d] = [$this->issue('C', done: true), $this->issue('D')];
        $e = $this->issue('E');

        $this->link($a, $b);
        $this->link($c, $d);

        $this->assertSame(['B'], $this->titles('is:blocked'));
        $this->assertSame(['A'], $this->titles('is:blocking'));
        $this->assertSame(['A', 'D', 'E'], $this->titles('-is:blocked'), 'D waits only on finished work.');
    }

    #[Test]
    public function delaying_means_the_work_waiting_cannot_start_when_planned_and_sql_agrees_with_php(): void
    {
        // Ends on day 10; the work waiting was to start on day 5.
        [$late, $waitsLate] = [$this->issue('Late', to: 10), $this->issue('Waits on late', from: 5, to: 8)];
        // Ends on day 2; the work waiting starts on day 5. Fine.
        [$fine, $waitsFine] = [$this->issue('Fine', to: 2), $this->issue('Waits on fine', from: 5)];
        // Due three days ago and still open: it cannot end before today, and the work
        // waiting was meant to start yesterday.
        [$overdue, $waitsOverdue] = [$this->issue('Overdue', to: -3), $this->issue('Waits on overdue', from: -1)];
        // No dates at all, holding up work that was meant to start two days ago.
        [$undated, $waitsUndated] = [$this->issue('Undated'), $this->issue('Waits on undated', from: -2)];
        // Finished: delays nothing any more.
        [$done, $waitsDone] = [$this->issue('Done', to: 20, done: true), $this->issue('Waits on done', from: 1)];

        foreach ([[$late, $waitsLate], [$fine, $waitsFine], [$overdue, $waitsOverdue], [$undated, $waitsUndated], [$done, $waitsDone]] as [$blocker, $waiting]) {
            $this->link($blocker, $waiting);
        }

        $this->assertSame(['Late', 'Overdue', 'Undated'], $this->titles('is:delaying'));

        $days = fn (Issue $b, Issue $w) => $this->tenant(fn () => Blockage::days(Issue::with('status')->find($b->id), Issue::with('status')->find($w->id)));

        $this->assertSame([5, 0, 1, 2, 0], [
            $days($late, $waitsLate), $days($fine, $waitsFine), $days($overdue, $waitsOverdue),
            $days($undated, $waitsUndated), $days($done, $waitsDone),
        ]);
    }

    #[Test]
    public function list_rows_say_what_they_wait_on_and_what_they_hold_up(): void
    {
        [$blocker, $waiting] = [$this->issue('Blocker', to: 10), $this->issue('Waiting', from: 5)];
        $this->link($blocker, $waiting);

        $rows = collect($this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/issues'))
            ->viewData('page')['props']['issues'])->keyBy('title');

        $this->assertSame([['key' => $blocker->key, 'delay_days' => 5]], $rows['Waiting']['blocked_by']);
        $this->assertSame(['count' => 1, 'days' => 5], $rows['Blocker']['delaying']);
        $this->assertNull($rows['Waiting']['delaying']);
    }

    #[Test]
    public function a_client_is_never_told_about_a_blocker_they_cannot_see(): void
    {
        [$internal, $shared] = [$this->issue('Internal refactor', to: 10), $this->issue('Homepage', from: 5, visibility: 'client')];
        $this->link($internal, $shared);

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        $props = $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/issues'))->viewData('page')['props'];
        $this->assertSame([], collect($props['issues'])->firstWhere('title', 'Homepage')['blocked_by']);
        $this->assertStringNotContainsString($internal->key, json_encode(collect($props)->except('ziggy')->all()));

        $blocked = $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/issues?q=is:blocked'))->viewData('page')['props']['issues'];
        $this->assertCount(0, $blocked, 'is:blocked admitted that something hidden is in the way.');
    }

    #[Test]
    public function insights_ranks_blockers_by_delay_then_by_everything_waiting_down_the_chain(): void
    {
        [$head, $middle, $tail] = [$this->issue('Head of chain', to: 3), $this->issue('Middle', from: 4, to: 6), $this->issue('Tail', from: 7)];
        $this->link($head, $middle);
        $this->link($middle, $tail);

        [$late, $waiting] = [$this->issue('Late designs', to: 12), $this->issue('Build', from: 8)];
        $this->link($late, $waiting);

        $blockers = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/insights'))
            ->viewData('page')['props']['blockers'];

        $this->assertSame(['Late designs', 'Head of chain', 'Middle'], array_column($blockers, 'title'));
        $this->assertSame(4, $blockers[0]['delay_days']);
        $this->assertSame(2, $blockers[1]['chain'], 'The head holds up the middle and, through it, the tail.');
        $this->assertSame(1, $blockers[1]['waiting']);
    }

    #[Test]
    public function a_delay_a_blocker_caused_is_on_record_after_the_fact(): void
    {
        [$design, $build] = [$this->issue('Design', from: 1, to: 5), $this->issue('Build', from: 6, to: 9)];
        $this->link($design, $build);

        $this->actingAs($this->owner)->patch($this->workspaceUrl($this->workspace, "/issues/{$design->key}/schedule"), [
            'start_on' => now()->addDays(1)->toDateString(), 'due_on' => now()->addDays(10)->toDateString(),
            'version' => $design->scheduleVersion(), 'shift_dependents' => true,
        ])->assertSessionHasNoErrors();

        $delays = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/insights'))
            ->viewData('page')['props']['delaysCaused'];

        $this->assertCount(1, $delays);
        $this->assertSame([$design->key, $build->key, 4], [$delays[0]['blocker'], $delays[0]['key'], $delays[0]['days']]);

        // Not something a request can claim.
        $this->actingAs($this->owner)->patch($this->workspaceUrl($this->workspace, "/issues/{$build->key}"), [
            'due_on' => now()->addDays(30)->toDateString(), 'because' => 'KD-999',
        ]);
        $this->assertCount(1, $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/insights'))->viewData('page')['props']['delaysCaused']);
    }

    /** @return array<int, string> */
    private function titles(string $q): array
    {
        return collect($this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/issues?q='.urlencode($q)))
            ->viewData('page')['props']['issues'])
            ->pluck('title')->sort()->values()->all();
    }

    private function issue(string $title, ?int $from = null, ?int $to = null, bool $done = false, string $visibility = 'internal'): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title, 'visibility' => $visibility], $this->owner));

        $issue->forceFill([
            'start_on' => $from === null ? null : now()->addDays($from)->toDateString(),
            'due_on' => $to === null ? null : now()->addDays($to)->toDateString(),
            'status_id' => $done
                ? $this->tenant(fn () => $this->project->statuses()->where('category', StatusCategory::Done->value)->firstOrFail()->id)
                : $issue->status_id,
        ])->save();

        return $this->tenant(fn () => Issue::findOrFail($issue->id));
    }

    private function link(Issue $blocker, Issue $waiting): void
    {
        $this->tenant(fn () => app(RelateIssues::class)->handle($blocker, $waiting, RelationType::Blocks, $this->owner));
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
