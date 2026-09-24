<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\RelateIssues;
use App\Enums\IssueEventType;
use App\Enums\RelationType;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dependencies as the timeline uses them: no loops, and when a blocker moves later,
 * the work waiting on it can move with it — later only, and only as far as it must.
 */
class TimelineDependenciesTest extends TestCase
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
    public function a_loop_of_blockers_is_refused_however_long_the_way_round(): void
    {
        [$a, $b, $c] = [$this->issue('A'), $this->issue('B'), $this->issue('C')];

        $this->link($a, $b);
        $this->link($b, $c);

        $this->actingAs($this->owner)
            ->post($this->url($c, '/relations'), ['key' => $a->key, 'type' => 'blocks'])
            ->assertSessionHasErrors(['key' => "{$a->key} already waits on {$c->key}, so {$c->key} cannot wait on it too."]);

        $this->actingAs($this->owner)
            ->post($this->url($a, '/relations'), ['key' => $c->key, 'type' => 'blocked_by'])
            ->assertSessionHasErrors('key');

        // Drawing the same direction again is harmless, and other kinds of link are not chains.
        $this->actingAs($this->owner)->post($this->url($a, '/relations'), ['key' => $c->key, 'type' => 'blocks'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->url($c, '/relations'), ['key' => $a->key, 'type' => 'relates_to'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function moving_a_blocker_later_moves_the_chain_behind_it_only_as_far_as_it_must(): void
    {
        $design = $this->issue('Design', from: 1, to: 5);
        $build = $this->issue('Build', from: 5, to: 8);
        $launch = $this->issue('Launch', from: 12, to: 12);
        $done = $this->issue('Old task', from: 3, to: 4, done: true);

        $this->link($design, $build);
        $this->link($build, $launch);
        $this->link($design, $done);

        // Design now ends on day 10: Build (day 5) must start on day 10, which ends it
        // on day 13, which pushes Launch (day 12) to day 13.
        $this->actingAs($this->owner)
            ->patch($this->url($design, '/schedule'), [
                'start_on' => $this->day(1), 'due_on' => $this->day(10),
                'version' => $design->scheduleVersion(), 'shift_dependents' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "{$design->key} moved, and 2 issues waiting on it moved later with it: {$build->key}, {$launch->key}.");

        $this->assertDates($build, 10, 13);
        $this->assertDates($launch, 13, 13);
        $this->assertDates($done, 3, 4);

        $this->assertTrue($this->tenant(fn () => $this->reload($build)->events()->where('type', IssueEventType::DatesChanged->value)->exists()));
    }

    #[Test]
    public function without_asking_or_when_moving_earlier_nothing_else_moves(): void
    {
        $design = $this->issue('Design', from: 1, to: 5);
        $build = $this->issue('Build', from: 6, to: 8);
        $this->link($design, $build);

        $this->actingAs($this->owner)->patch($this->url($design, '/schedule'), [
            'start_on' => $this->day(1), 'due_on' => $this->day(9), 'version' => $design->scheduleVersion(),
        ])->assertSessionHasNoErrors();

        $this->assertDates($build, 6, 8);

        $design = $this->reload($design);

        $this->actingAs($this->owner)->patch($this->url($design, '/schedule'), [
            'start_on' => $this->day(0), 'due_on' => $this->day(2),
            'version' => $design->scheduleVersion(), 'shift_dependents' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDates($build, 6, 8);
    }

    #[Test]
    public function a_dependent_with_only_a_due_date_keeps_only_a_due_date(): void
    {
        $design = $this->issue('Design', from: 1, to: 5);
        $signoff = $this->issue('Sign-off', to: 6);
        $this->link($design, $signoff);

        $this->actingAs($this->owner)->patch($this->url($design, '/schedule'), [
            'start_on' => $this->day(1), 'due_on' => $this->day(9),
            'version' => $design->scheduleVersion(), 'shift_dependents' => true,
        ])->assertSessionHasNoErrors();

        $signoff = $this->reload($signoff);
        $this->assertNull($signoff->start_on);
        $this->assertSame($this->day(9), $signoff->due_on->toDateString());
    }

    #[Test]
    public function the_timeline_sends_every_blocker_for_the_chart_to_draw(): void
    {
        $design = $this->issue('Design', from: 1, to: 3);
        $build = $this->issue('Build', from: 5, to: 8);
        $this->link($design, $build);

        $rows = collect($this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/timeline'))
            ->viewData('page')['props']['rows'])->keyBy('key');

        $this->assertSame([$design->key], $rows[$build->key]['blocked_by']);
        $this->assertSame([], $rows[$build->key]['conflicts'], 'Not late, so not red — but still a line.');
    }

    private function assertDates(Issue $issue, int $from, int $to): void
    {
        $issue = $this->reload($issue);

        $this->assertSame([$this->day($from), $this->day($to)], [$issue->start_on->toDateString(), $issue->due_on->toDateString()], $issue->title);
    }

    private function issue(string $title, ?int $from = null, ?int $to = null, bool $done = false): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title], $this->owner));

        $issue->forceFill([
            'start_on' => $from === null ? null : $this->day($from),
            'due_on' => $to === null ? null : $this->day($to),
            'status_id' => $done
                ? $this->tenant(fn () => $this->project->statuses()->where('category', StatusCategory::Done->value)->firstOrFail()->id)
                : $issue->status_id,
        ])->save();

        return $this->reload($issue);
    }

    private function link(Issue $blocker, Issue $blocked): void
    {
        $this->tenant(fn () => app(RelateIssues::class)->handle($blocker, $blocked, RelationType::Blocks, $this->owner));
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::findOrFail($issue->id));
    }

    private function url(Issue $issue, string $suffix): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$issue->key}{$suffix}");
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
