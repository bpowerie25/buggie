<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Support\Issues\BoardRank;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardOrderingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: array<int, Issue>} */
    private function board(int $count = 3): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $issues = app(Tenancy::class)->run($workspace, function () use ($owner, $count) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return collect(range(1, $count))
                ->map(fn (int $i) => app(CreateIssue::class)->handle($project, ['title' => "Issue {$i}"], $owner))
                ->all();
        });

        return [$workspace, $owner, $issues];
    }

    /** The keys of a workspace's issues in board order. */
    private function order($workspace): array
    {
        return app(Tenancy::class)->run($workspace, fn () => Issue::query()
            ->orderByRaw('board_rank NULLS LAST, id')
            ->pluck('title')
            ->all());
    }

    #[Test]
    public function a_new_issue_goes_to_the_bottom(): void
    {
        [$workspace, , $issues] = $this->board();

        $this->assertSame(['Issue 1', 'Issue 2', 'Issue 3'], $this->order($workspace));
    }

    #[Test]
    public function a_card_can_be_dragged_to_the_top(): void
    {
        [$workspace, $owner, $issues] = $this->board();

        // Dropped above everything: no card after it, Issue 1 before it.
        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[2]->key}/rank"), [
                'after' => null,
                'before' => $issues[0]->key,
            ])
            ->assertRedirect();

        $this->assertSame(['Issue 3', 'Issue 1', 'Issue 2'], $this->order($workspace));
    }

    #[Test]
    public function a_card_can_be_dragged_between_two_others(): void
    {
        [$workspace, $owner, $issues] = $this->board();

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}/rank"), [
                'after' => $issues[1]->key,
                'before' => $issues[2]->key,
            ])
            ->assertRedirect();

        $this->assertSame(['Issue 2', 'Issue 1', 'Issue 3'], $this->order($workspace));
    }

    #[Test]
    public function a_card_can_be_dragged_to_the_bottom(): void
    {
        [$workspace, $owner, $issues] = $this->board();

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}/rank"), [
                'after' => $issues[2]->key,
                'before' => null,
            ])
            ->assertRedirect();

        $this->assertSame(['Issue 2', 'Issue 3', 'Issue 1'], $this->order($workspace));
    }

    #[Test]
    public function an_exhausted_gap_renumbers_rather_than_refusing(): void
    {
        // Somebody who has dropped forty cards into the same gap should not be told
        // the board is full.
        [$workspace, $owner, $issues] = $this->board();

        app(Tenancy::class)->run($workspace, function () use ($issues) {
            // Two neighbours with nothing between them worth halving.
            $issues[0]->forceFill(['board_rank' => 1.0])->save();
            $issues[1]->forceFill(['board_rank' => 1.0 + (BoardRank::MIN_GAP / 4)])->save();
            $issues[2]->forceFill(['board_rank' => 5000.0])->save();
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[2]->key}/rank"), [
                'after' => $issues[0]->key,
                'before' => $issues[1]->key,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['Issue 1', 'Issue 3', 'Issue 2'], $this->order($workspace));
    }

    #[Test]
    public function renumbering_keeps_the_order_it_found(): void
    {
        [$workspace, , $issues] = $this->board(4);

        app(Tenancy::class)->run($workspace, function () use ($workspace) {
            $before = $this->order($workspace);

            BoardRank::renumber($workspace->id);

            $this->assertSame($before, $this->order($workspace), 'Renumbering reordered the board.');
        });
    }

    #[Test]
    public function ranking_records_no_activity_and_does_not_touch_the_issue(): void
    {
        // Dragging a card is bookkeeping about where it sits, not a change to the
        // work. An activity entry per drag would bury the real history.
        [$workspace, $owner, $issues] = $this->board();

        $before = $issues[0]->fresh();

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[0]->key}/rank"), [
                'after' => $issues[2]->key,
                'before' => null,
            ])
            ->assertRedirect();

        $after = $issues[0]->fresh();

        $this->assertSame(
            $before->events()->count(),
            $after->events()->count(),
            'Dragging a card wrote an activity event.',
        );
    }

    #[Test]
    public function a_client_cannot_reorder_the_board(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issues] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return [$project, [
                app(CreateIssue::class)->handle($project, ['title' => 'A', 'visibility' => 'client'], $staff),
                app(CreateIssue::class)->handle($project, ['title' => 'B', 'visibility' => 'client'], $staff),
            ]];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($client)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[1]->key}/rank"), [
                'after' => null,
                'before' => $issues[0]->key,
            ])
            ->assertForbidden();

        // The paired control: staff on the same issue can.
        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issues[1]->key}/rank"), [
                'after' => null,
                'before' => $issues[0]->key,
            ])
            ->assertRedirect();
    }

    #[Test]
    public function a_neighbour_in_another_workspace_is_not_a_neighbour(): void
    {
        // The keys are guessable and `exists:issues,key` is not workspace-scoped, so
        // this is the request worth being suspicious of.
        [$acme, $owner, $ours] = $this->board();
        [$other, $stranger] = $this->workspaceWithMember(WorkspaceRole::Owner, 'other');

        $theirs = app(Tenancy::class)->run($other, function () use ($stranger) {
            $project = app(CreateProject::class)->handle(['name' => 'Theirs']);

            return app(CreateIssue::class)->handle($project, ['title' => 'Not ours'], $stranger);
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($acme, "/issues/{$ours[0]->key}/rank"), [
                'after' => $theirs->key,
                'before' => null,
            ])
            ->assertSessionHasErrors('after');

        // Refused outright rather than quietly read as "no neighbour", which would
        // have moved the card somewhere nobody asked for.
        $this->assertSame(['Issue 1', 'Issue 2', 'Issue 3'], $this->order($acme));
    }

    #[Test]
    public function the_board_is_ordered_by_rank_and_the_list_by_priority(): void
    {
        // Two questions, two sorts. A card dragged down the board must not quietly
        // leave the top of everybody's list.
        [$workspace, $owner, $issues] = $this->board();

        app(Tenancy::class)->run($workspace, fn () => $issues[2]
            ->forceFill(['priority' => 4])->save());

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?layout=board'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issues.0.title', 'Issue 1'));

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issues.0.title', 'Issue 3'));
    }

    // ------------------------------------------------------------- wip limits

    #[Test]
    public function a_status_can_be_given_a_limit_and_have_it_taken_away(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $status = app(Tenancy::class)->run($workspace, function () {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return \App\Models\Status::where('project_id', $project->id)->firstOrFail();
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                'name' => $status->name, 'color' => $status->color, 'wip_limit' => 3,
            ])
            ->assertRedirect();

        $this->assertSame(3, $status->fresh()->wip_limit);

        // Blank clears it. "No limit" and "a limit of nothing" are different claims.
        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                'name' => $status->name, 'color' => $status->color, 'wip_limit' => null,
            ])
            ->assertRedirect();

        $this->assertNull($status->fresh()->wip_limit);
    }

    #[Test]
    public function a_limit_of_zero_is_refused(): void
    {
        // A column nothing may enter is a column to delete.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $status = app(Tenancy::class)->run($workspace, function () {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return \App\Models\Status::where('project_id', $project->id)->firstOrFail();
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                'name' => $status->name, 'color' => $status->color, 'wip_limit' => 0,
            ])
            ->assertSessionHasErrors('wip_limit');
    }

    #[Test]
    public function going_over_a_limit_is_allowed(): void
    {
        /*
         * Deliberate. A limit that refuses the drop turns "finish something before
         * starting another" into an obstacle to be worked around, usually by
         * abandoning the board. It is shown, it is counted, and it goes red.
         */
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        [$issues, $status] = app(Tenancy::class)->run($workspace, function () use ($owner) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            $status = \App\Models\Status::where('project_id', $project->id)
                ->where('is_default', true)->firstOrFail();

            $status->forceFill(['wip_limit' => 1])->save();

            return [collect(range(1, 3))
                ->map(fn (int $i) => app(CreateIssue::class)->handle($project, ['title' => "Issue {$i}"], $owner))
                ->all(), $status];
        });

        // All three sit in the default status, three times the limit, and nothing
        // refused them.
        $this->assertSame(3, app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::where('status_id', $status->id)->count(),
        ));
    }

    #[Test]
    public function the_board_is_told_the_limit(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            \App\Models\Status::where('project_id', $project->id)
                ->where('is_default', true)
                ->first()
                ->forceFill(['wip_limit' => 5])
                ->save();
        });

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?layout=board'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has(
                'facets.statuses_by_project',
                fn ($byProject) => $byProject->each(
                    fn ($statuses) => $statuses->each(fn ($s) => $s->has('wip_limit')->etc()),
                ),
            ));
    }
}
