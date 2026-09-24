<?php

namespace Tests\Feature;

use App\Enums\RelationType;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The timeline is mostly a set of refusals: not drawing a bar where there is no date,
 * not widening a parent's own deadline to fit its children, not calling finished work
 * late. Each of those is only worth testing next to the case it is refusing, so every
 * negative here has a positive control in the same test — otherwise "the undated
 * issue was not drawn" passes beautifully on a page that draws nothing at all.
 */
class TimelineTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed, because every assertion below is about a date relative to "now". */
    private const TODAY = '2026-10-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 09:00'));
    }

    // ------------------------------------------------------------------- the gate

    #[Test]
    public function the_screen_renders_for_staff(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $this->issue($workspace, $project, [
            'title' => 'Rebuild checkout',
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-20',
        ]);

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/timeline'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('timeline/index')
                ->has('rows', 1)
                ->has('undated')
                ->has('axis')
                ->where('axis.today', self::TODAY));
    }

    #[Test]
    public function a_client_cannot_reach_it_unless_a_project_shares_it(): void
    {
        // A timeline is a plan across the workspace. A client scoped to one project
        // out of twenty either learns about the other nineteen or is not looking at
        // a plan — so a client sees one project's, and only where the team has chosen
        // to show it (ClientTimelineTest). Unshared, there is nothing to find: 404.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $project = $this->project($workspace);

        $client = $this->member($workspace, WorkspaceRole::Client);
        $this->inWorkspace($workspace, fn () => $client->projects()->attach($project->id));

        // Something the client is explicitly allowed to see, so the refusal below is
        // about the screen rather than about there being nothing on it.
        $this->issue($workspace, $project, [
            'title' => 'Theirs',
            'visibility' => \App\Enums\IssueVisibility::Client->value,
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-20',
        ]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/timeline'))
            ->assertNotFound();

        // The positive control: the same URL, the same workspace, the same issue.
        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/timeline'))
            ->assertOk();
    }

    #[Test]
    public function nothing_leaks_across_workspaces(): void
    {
        [$acme, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$other, $stranger] = $this->workspaceWithMember(slug: 'other');

        $theirs = $this->project($acme, 'Theirs');
        $this->issue($acme, $theirs, [
            'title' => 'Acme only',
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-20',
        ]);

        $mine = $this->project($other, 'Mine');
        $this->issue($other, $mine, [
            'title' => 'Other only',
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-20',
        ]);

        $theirKeys = $this->rows($acme, $owner)->keys();
        $otherKeys = $this->rows($other, $stranger)->keys();

        // Neither sees the other, and — the control — each sees exactly one row of
        // its own, so this is not two empty pages agreeing with each other.
        $this->assertCount(1, $theirKeys);
        $this->assertCount(1, $otherKeys);
        $this->assertEmpty($theirKeys->intersect($otherKeys));
    }

    // ------------------------------------------------------------------ the shapes

    #[Test]
    public function an_issue_with_no_dates_at_all_is_listed_rather_than_drawn(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $drawn = $this->issue($workspace, $project, [
            'title' => 'Planned',
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-20',
        ]);

        $listed = $this->issue($workspace, $project, ['title' => 'Someday']);

        $props = $this->props($workspace, $owner);

        // The negative and its control, from the same response: one is drawn, the
        // other is not, and the other is not simply missing.
        $this->assertSame([$drawn->key], collect($props['rows'])->pluck('key')->all());
        $this->assertSame([$listed->key], collect($props['undated'])->pluck('key')->all());
    }

    #[Test]
    public function one_date_draws_a_milestone_and_two_draw_a_bar(): void
    {
        // The whole reason start_on is a column. Deriving it from due_on minus the
        // estimate, or from created_at, would make both of these bars — and only one
        // of them is a span anybody actually committed to.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $spanned = $this->issue($workspace, $project, [
            'title' => 'Spanned',
            'start_on' => '2026-10-12',
            'due_on' => '2026-10-24',
        ]);

        $dueOnly = $this->issue($workspace, $project, [
            'title' => 'Due only',
            'due_on' => '2026-10-24',
        ]);

        $startOnly = $this->issue($workspace, $project, [
            'title' => 'Start only',
            'start_on' => '2026-10-12',
        ]);

        $rows = $this->rows($workspace, $owner);

        $this->assertSame('bar', $rows[$spanned->key]['kind']);
        $this->assertSame('2026-10-12', $rows[$spanned->key]['start']);
        $this->assertSame('2026-10-24', $rows[$spanned->key]['end']);

        $this->assertSame('milestone', $rows[$dueOnly->key]['kind']);
        $this->assertSame('due', $rows[$dueOnly->key]['anchor']);
        // A point, not a span: both ends are the one date that is known.
        $this->assertSame('2026-10-24', $rows[$dueOnly->key]['start']);
        $this->assertSame('2026-10-24', $rows[$dueOnly->key]['end']);

        $this->assertSame('milestone', $rows[$startOnly->key]['kind']);
        $this->assertSame('start', $rows[$startOnly->key]['anchor']);
        $this->assertSame('2026-10-12', $rows[$startOnly->key]['end']);
    }

    #[Test]
    public function a_parent_with_no_dates_of_its_own_spans_its_children(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $parent = $this->issue($workspace, $project, ['title' => 'Release 3']);

        $first = $this->issue($workspace, $project, [
            'title' => 'Early', 'parent_id' => $parent->id,
            'start_on' => '2026-10-12', 'due_on' => '2026-10-18',
        ]);

        $last = $this->issue($workspace, $project, [
            'title' => 'Late', 'parent_id' => $parent->id,
            'start_on' => '2026-10-20', 'due_on' => '2026-11-04',
        ]);

        $props = $this->props($workspace, $owner);
        $rows = collect($props['rows'])->keyBy('key');

        $this->assertSame('rollup', $rows[$parent->key]['kind']);
        $this->assertSame('2026-10-12', $rows[$parent->key]['start']);
        $this->assertSame('2026-11-04', $rows[$parent->key]['end']);
        $this->assertSame(2, $rows[$parent->key]['children']);

        // It is drawn, not filed under "no dates" — which is where it would land if
        // the rollup were not happening.
        $this->assertEmpty($props['undated']);

        // The children sit under it, indented and in date order.
        $this->assertSame(
            [$parent->key, $first->key, $last->key],
            collect($props['rows'])->pluck('key')->all(),
        );
        $this->assertSame([0, 1, 1], collect($props['rows'])->pluck('depth')->all());
    }

    #[Test]
    public function a_parent_with_its_own_dates_keeps_them(): void
    {
        // The control for the test above, and the more important half: a date
        // somebody typed is a commitment, and silently widening it to fit the work
        // underneath is how a deadline stops meaning anything.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $parent = $this->issue($workspace, $project, [
            'title' => 'Release 3', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ]);

        $this->issue($workspace, $project, [
            'title' => 'Overruns', 'parent_id' => $parent->id,
            'start_on' => '2026-10-14', 'due_on' => '2026-11-30',
        ]);

        $row = $this->rows($workspace, $owner)[$parent->key];

        $this->assertSame('bar', $row['kind']);
        $this->assertSame('2026-10-20', $row['end'], 'The parent kept its own due date.');
    }

    // ------------------------------------------------------------------- lateness

    #[Test]
    public function an_open_issue_past_its_due_date_is_flagged_and_nothing_else_is(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $late = $this->issue($workspace, $project, [
            'title' => 'Late', 'start_on' => '2026-10-02', 'due_on' => '2026-10-10',
        ]);

        $soon = $this->issue($workspace, $project, [
            'title' => 'Not yet', 'start_on' => '2026-10-12', 'due_on' => '2026-10-30',
        ]);

        $today = $this->issue($workspace, $project, [
            'title' => 'Due today', 'start_on' => '2026-10-12', 'due_on' => self::TODAY,
        ]);

        $shipped = $this->issue($workspace, $project, [
            'title' => 'Shipped late but shipped',
            'start_on' => '2026-10-02', 'due_on' => '2026-10-10',
        ], StatusCategory::Done);

        // is:any, because the closed one is the control and the default is is:open.
        $rows = $this->rows($workspace, $owner, ['q' => 'is:any']);

        $this->assertTrue($rows[$late->key]['overdue']);
        $this->assertFalse($rows[$soon->key]['overdue']);
        // Something due today has until the end of the day.
        $this->assertFalse($rows[$today->key]['overdue']);
        // Finished, not late. An issue nobody will work on again cannot be chased.
        $this->assertFalse($rows[$shipped->key]['overdue']);
    }

    // --------------------------------------------------------------------- window

    #[Test]
    public function the_date_range_narrows_the_rows(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $near = $this->issue($workspace, $project, [
            'title' => 'This month', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ]);

        $far = $this->issue($workspace, $project, [
            'title' => 'Next year', 'start_on' => '2027-06-01', 'due_on' => '2027-06-30',
        ]);

        // The control first: a window wide enough holds both.
        $wide = $this->rows($workspace, $owner, ['from' => '2026-10-01', 'to' => '2027-12-31']);
        $this->assertTrue($wide->has($near->key));
        $this->assertTrue($wide->has($far->key));

        $narrow = $this->rows($workspace, $owner, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertTrue($narrow->has($near->key));
        $this->assertFalse($narrow->has($far->key));
    }

    #[Test]
    public function work_straddling_the_edge_of_the_window_is_kept(): void
    {
        // Overlap, not containment. A six-month piece of work is not absent from
        // October because it started in September.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $straddling = $this->issue($workspace, $project, [
            'title' => 'Long haul', 'start_on' => '2026-09-01', 'due_on' => '2026-12-01',
        ]);

        $before = $this->issue($workspace, $project, [
            'title' => 'Finished before', 'start_on' => '2026-08-01', 'due_on' => '2026-09-15',
        ]);

        $rows = $this->rows($workspace, $owner, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertTrue($rows->has($straddling->key));
        $this->assertFalse($rows->has($before->key));
    }

    #[Test]
    public function a_backwards_range_is_swapped_rather_than_rejected(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/timeline?from=2026-12-31&to=2026-10-01'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('axis.from', '2026-10-01')
                ->where('axis.to', '2026-12-31'));
    }

    #[Test]
    public function the_axis_scale_follows_the_range(): void
    {
        // A year at day resolution is 365 gridlines with bars drawn between them.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $interval = fn (string $from, string $to) => $this->props(
            $workspace, $owner, ['from' => $from, 'to' => $to],
        )['axis']['interval'];

        $this->assertSame('day', $interval('2026-10-01', '2026-11-01'));
        $this->assertSame('week', $interval('2026-10-01', '2027-01-31'));
        $this->assertSame('month', $interval('2026-10-01', '2028-10-01'));
    }

    // ---------------------------------------------------------------- the filters

    #[Test]
    public function the_query_language_is_the_filter(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $web = $this->project($workspace, 'Web');
        $api = $this->project($workspace, 'Api');

        $open = $this->issue($workspace, $web, [
            'title' => 'Open one', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ]);

        $closed = $this->issue($workspace, $web, [
            'title' => 'Closed one', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ], StatusCategory::Done);

        $elsewhere = $this->issue($workspace, $api, [
            'title' => 'Other project', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ]);

        // Defaults to open, like every other surface reading this language.
        $default = $this->rows($workspace, $owner);
        $this->assertTrue($default->has($open->key));
        $this->assertFalse($default->has($closed->key));

        // The control: is:any brings it back, so the absence above was the filter
        // rather than the issue never being drawable.
        $this->assertTrue($this->rows($workspace, $owner, ['q' => 'is:any'])->has($closed->key));

        // Narrowing to a project is an operator, not a parameter beside the query.
        $scoped = $this->rows($workspace, $owner, ['q' => 'project:'.$web->slug]);
        $this->assertTrue($scoped->has($open->key));
        $this->assertFalse($scoped->has($elsewhere->key));
    }

    // ----------------------------------------------------------------- dependency

    #[Test]
    public function a_blocker_is_named_and_only_a_late_one_draws_a_line(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $lateBlocker = $this->issue($workspace, $project, [
            'title' => 'Runs over', 'start_on' => '2026-10-05', 'due_on' => '2026-10-25',
        ]);

        $tidyBlocker = $this->issue($workspace, $project, [
            'title' => 'Finishes in time', 'start_on' => '2026-10-05', 'due_on' => '2026-10-10',
        ]);

        $conflicted = $this->issue($workspace, $project, [
            'title' => 'Starts too soon', 'start_on' => '2026-10-15', 'due_on' => '2026-10-30',
        ]);

        $fine = $this->issue($workspace, $project, [
            'title' => 'Starts after', 'start_on' => '2026-10-15', 'due_on' => '2026-10-30',
        ]);

        $this->blocks($workspace, blocker: $lateBlocker, blocked: $conflicted);
        $this->blocks($workspace, blocker: $tidyBlocker, blocked: $fine);

        $rows = $this->rows($workspace, $owner);

        // Both are named as blocked; that is the "at minimum" half.
        $this->assertSame([$lateBlocker->key], $rows[$conflicted->key]['blocked_by']);
        $this->assertSame([$tidyBlocker->key], $rows[$fine->key]['blocked_by']);

        // Only the dependency actually in trouble gets a connector. A line for every
        // `blocks` relation is a ball of string that hides the one that matters.
        $this->assertSame([$lateBlocker->key], $rows[$conflicted->key]['conflicts']);
        $this->assertSame([], $rows[$fine->key]['conflicts']);
    }

    #[Test]
    public function a_blocker_that_is_not_on_screen_is_named_but_not_drawn(): void
    {
        // There is nowhere to draw a line to. Naming it is still useful.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $offscreen = $this->issue($workspace, $project, [
            'title' => 'Off to the right', 'start_on' => '2027-06-01', 'due_on' => '2027-06-30',
        ]);

        $blocked = $this->issue($workspace, $project, [
            'title' => 'Waiting', 'start_on' => '2026-10-12', 'due_on' => '2026-10-20',
        ]);

        $this->blocks($workspace, blocker: $offscreen, blocked: $blocked);

        $rows = $this->rows($workspace, $owner, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertSame([$offscreen->key], $rows[$blocked->key]['blocked_by']);
        $this->assertSame([], $rows[$blocked->key]['conflicts']);

        // The control: widen the window and the same dependency does draw.
        $wide = $this->rows($workspace, $owner, ['from' => '2026-10-01', 'to' => '2027-12-31']);
        $this->assertSame([$offscreen->key], $wide[$blocked->key]['conflicts']);
    }

    #[Test]
    public function a_page_full_of_related_work_does_not_lazy_load(): void
    {
        // Strict mode turns a lazy load into an exception, and a timeline is a loop
        // over issues — which is exactly where a forgotten eager load becomes a 500
        // for everybody rather than a slow page for somebody.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);

        $previous = null;

        foreach (range(1, 6) as $i) {
            $parent = $this->issue($workspace, $project, [
                'title' => "Epic {$i}", 'start_on' => '2026-10-12', 'due_on' => '2026-10-28',
            ]);

            $child = $this->issue($workspace, $project, [
                'title' => "Task {$i}", 'parent_id' => $parent->id,
                'start_on' => '2026-10-13', 'due_on' => '2026-10-19',
            ]);

            if ($previous !== null) {
                $this->blocks($workspace, blocker: $previous, blocked: $child);
            }

            $previous = $child;
        }

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/timeline'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 12));
    }

    // -------------------------------------------------------------- the issue page

    #[Test]
    public function a_start_date_can_be_set_and_cleared_from_the_issue_page(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);
        $issue = $this->issue($workspace, $project, ['title' => 'Someday']);

        $this->actingAs($owner)->patch(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}"),
            ['start_on' => '2026-10-12'],
        )->assertRedirect();

        $this->assertSame('2026-10-12', $issue->fresh()->start_on->toDateString());

        // A start date you cannot remove is a date that stays wrong for ever.
        $this->actingAs($owner)->patch(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}"),
            ['start_on' => null],
        )->assertRedirect();

        $this->assertNull($issue->fresh()->start_on);
    }

    #[Test]
    public function the_issue_page_carries_the_start_date(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $project = $this->project($workspace);
        $issue = $this->issue($workspace, $project, [
            'title' => 'Planned', 'start_on' => '2026-10-12',
        ]);

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issue.start_on', '2026-10-12'));
    }

    // ------------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $params */
    private function props(Workspace $workspace, User $user, array $params = []): array
    {
        $query = $params === [] ? '' : '?'.http_build_query($params);

        return $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/timeline'.$query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /**
     * The drawn rows, keyed by issue key.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<string, array<string, mixed>>
     */
    private function rows(Workspace $workspace, User $user, array $params = []): Collection
    {
        return collect($this->props($workspace, $user, $params)['rows'])->keyBy('key');
    }

    private function project(Workspace $workspace, string $name = 'Site'): Project
    {
        return $this->inWorkspace(
            $workspace,
            fn () => app(\App\Actions\CreateProject::class)->handle(['name' => $name]),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function issue(
        Workspace $workspace,
        Project $project,
        array $attributes,
        ?StatusCategory $category = null,
    ): Issue {
        return $this->inWorkspace($workspace, function () use ($project, $attributes, $category) {
            $factory = Issue::factory();

            if ($category !== null) {
                $factory = $factory->inStatus($category->value);
            }

            return $factory->create(['project_id' => $project->id, ...$attributes]);
        });
    }

    private function blocks(Workspace $workspace, Issue $blocker, Issue $blocked): void
    {
        // Both halves, the way IssueRelationController writes them.
        $this->inWorkspace($workspace, function () use ($blocker, $blocked) {
            IssueRelation::create([
                'issue_id' => $blocked->id,
                'related_issue_id' => $blocker->id,
                'type' => RelationType::BlockedBy->value,
            ]);

            IssueRelation::create([
                'issue_id' => $blocker->id,
                'related_issue_id' => $blocked->id,
                'type' => RelationType::Blocks->value,
            ]);
        });
    }

    private function member(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, [
            'role' => $role->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function inWorkspace(Workspace $workspace, \Closure $callback): mixed
    {
        return app(Tenancy::class)->run($workspace, $callback);
    }
}
