<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\IssueEventType;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Phase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Issues\IssueQuery;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phases: the stages a job runs in, managed on the project, chosen on the issue,
 * filtered with `phase:`, and drawn on the timeline as headers over their work.
 */
class PhaseTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->workspace->update(['name' => 'Matrix']);
        $this->project = $this->tenant(fn () => Project::factory()->create(['name' => 'Kennco', 'key' => 'KD', 'slug' => 'kennco']));
    }

    #[Test]
    public function a_project_gets_phases_in_order_and_can_rename_reorder_and_delete_them(): void
    {
        foreach (['Discovery', 'Build', 'Design'] as $name) {
            $this->actingAs($this->owner)->post($this->projectUrl('/phases'), ['name' => $name])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->owner)->post($this->projectUrl('/phases'), ['name' => 'Design'])
            ->assertSessionHasErrors('name');

        [$discovery, $build, $design] = $this->phases();

        $this->actingAs($this->owner)
            ->put($this->projectUrl('/phases/order'), ['ids' => [$discovery->id, $design->id, $build->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Discovery', 'Design', 'Build'], $this->phases()->pluck('name')->all());

        $this->actingAs($this->owner)->patch($this->projectUrl("/phases/{$build->id}"), ['name' => 'Development'])
            ->assertSessionHasNoErrors();

        $issue = $this->issue('Homepage', phase: $design);

        $this->actingAs($this->owner)->delete($this->projectUrl("/phases/{$design->id}"))->assertSessionHasNoErrors();

        $this->assertSame(['Discovery', 'Development'], $this->phases()->pluck('name')->all());
        $this->assertNull($this->reload($issue)->phase_id, 'The issue should be kept, out of the phase.');
    }

    #[Test]
    public function an_order_that_leaves_one_out_or_names_another_projects_is_refused(): void
    {
        [$a, $b] = $this->tenant(fn () => [
            $this->project->phases()->create(['name' => 'A', 'position' => 0]),
            $this->project->phases()->create(['name' => 'B', 'position' => 1]),
        ]);
        $other = $this->tenant(fn () => Project::factory()->create()->phases()->create(['name' => 'X']));

        $this->actingAs($this->owner)->put($this->projectUrl('/phases/order'), ['ids' => [$b->id]])
            ->assertSessionHasErrors('ids');
        $this->actingAs($this->owner)->put($this->projectUrl('/phases/order'), ['ids' => [$b->id, $other->id]])
            ->assertSessionHasErrors('ids');

        $this->assertSame(['A', 'B'], $this->phases()->pluck('name')->all());
    }

    #[Test]
    public function an_issue_is_put_in_a_phase_of_its_own_project_and_the_move_is_recorded(): void
    {
        $build = $this->tenant(fn () => $this->project->phases()->create(['name' => 'Build']));
        $elsewhere = $this->tenant(fn () => Project::factory()->create()->phases()->create(['name' => 'Build']));
        $issue = $this->issue('Homepage');

        $this->actingAs($this->owner)->patch($this->issueUrl($issue), ['phase_id' => $elsewhere->id])
            ->assertSessionHasErrors('phase_id');

        $this->actingAs($this->owner)->patch($this->issueUrl($issue), ['phase_id' => $build->id])
            ->assertSessionHasNoErrors();

        $issue = $this->reload($issue);
        $this->assertSame($build->id, $issue->phase_id);

        $event = $this->tenant(fn () => $issue->events()->where('type', IssueEventType::PhaseChanged->value)->sole());
        $this->assertSame('Build', $event->data['to']);
        $this->assertTrue($event->is_internal);

        $this->actingAs($this->owner)->get($this->issueUrl($issue))
            ->assertInertia(fn ($page) => $page->where('issue.phase.name', 'Build')->where('phases.0.name', 'Build'));
    }

    #[Test]
    public function a_client_cannot_move_an_issue_between_phases_or_see_the_list(): void
    {
        $build = $this->tenant(fn () => $this->project->phases()->create(['name' => 'Build']));
        $issue = $this->issue('Homepage', visibility: 'client');
        $client = $this->client();

        $this->actingAs($client)->patch($this->issueUrl($issue), ['phase_id' => $build->id])->assertForbidden();
        $this->actingAs($client)->get($this->issueUrl($issue))->assertInertia(fn ($page) => $page->where('phases', []));
    }

    #[Test]
    public function the_query_language_filters_by_phase(): void
    {
        [$design, $build] = $this->tenant(fn () => [
            $this->project->phases()->create(['name' => 'Design']),
            $this->project->phases()->create(['name' => 'Build']),
        ]);

        $this->issue('Mockups', phase: $design);
        $this->issue('Templates', phase: $build);
        $this->issue('Unplaced');

        $titles = fn (string $q) => collect(
            $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/issues?q='.urlencode($q)))
                ->viewData('page')['props']['issues']
        )->pluck('title')->sort()->values()->all();

        $this->assertSame(['Mockups'], $titles('phase:Design'));
        $this->assertSame(['Templates', 'Unplaced'], $titles('-phase:Design'));
        $this->assertSame(['Unplaced'], $titles('no:phase'));
    }

    #[Test]
    public function the_timeline_draws_each_phase_over_its_work_in_the_order_the_job_runs(): void
    {
        [$design, $build] = $this->tenant(fn () => [
            $this->project->phases()->create(['name' => 'Design', 'position' => 0]),
            $this->project->phases()->create(['name' => 'Build', 'position' => 1]),
        ]);

        // Build starts first on the calendar, but Design is the earlier phase.
        $this->issue('Templates', phase: $build, from: 1, to: 5);
        $this->issue('Mockups', phase: $design, from: 3, to: 10);
        $this->issue('Wireframes', phase: $design, from: 6, to: 8);
        $this->issue('Hosting', from: 2, to: 4);
        $this->issue('Moodboard', phase: $design, from: 1, to: 2, done: true);

        $rows = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/timeline'))
            ->viewData('page')['props']['rows'];

        $this->assertSame(
            ['Design', 'Mockups', 'Wireframes', 'Build', 'Templates', 'No phase', 'Hosting'],
            array_column($rows, 'title'),
        );

        $header = $rows[0];
        $this->assertSame('phase', $header['kind']);
        $this->assertSame(now()->addDays(3)->toDateString(), $header['start']);
        $this->assertSame(now()->addDays(10)->toDateString(), $header['end']);
        // The finished moodboard is off the chart (open issues by default) but counts.
        $this->assertSame(['done' => 1, 'total' => 3], $header['progress']);
        $this->assertSame("phase-{$design->id}", $rows[1]['phase']);
    }

    #[Test]
    public function a_project_without_phases_draws_as_it_always_did(): void
    {
        $this->issue('Homepage', from: 1, to: 3);

        $rows = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/timeline'))
            ->viewData('page')['props']['rows'];

        $this->assertSame(['Homepage'], array_column($rows, 'title'));
        $this->assertArrayNotHasKey('phase', $rows[0]);
    }

    #[Test]
    public function a_clients_phase_counts_only_what_they_can_see(): void
    {
        $this->project->forceFill(['settings' => ['client_timeline' => true]])->save();
        $build = $this->tenant(fn () => $this->project->phases()->create(['name' => 'Build']));

        $this->issue('Homepage', phase: $build, from: 1, to: 3, visibility: 'client');
        $this->issue('Secret refactor', phase: $build, from: 1, to: 3);
        $this->issue('Old internal job', phase: $build, from: 1, to: 3, done: true);

        $rows = $this->actingAs($this->client())->get($this->workspaceUrl($this->workspace, '/timeline'))
            ->viewData('page')['props']['rows'];

        $this->assertSame(['Build', 'Homepage'], array_column($rows, 'title'));
        $this->assertSame(['done' => 0, 'total' => 1], $rows[0]['progress']);
    }

    #[Test]
    public function the_website_template_comes_with_phases_and_a_copy_keeps_them(): void
    {
        $source = $this->tenant(fn () => app(CreateProject::class)->handle(['name' => 'Acme', 'template' => 'client_website']));

        $this->assertSame(
            ['Discovery', 'Design', 'Build', 'Content', 'Launch'],
            $this->tenant(fn () => $source->phases()->pluck('name')->all()),
        );

        $copy = $this->tenant(fn () => app(CreateProject::class)->handle(['name' => 'Globex', 'source_project_id' => $source->id]));

        $this->assertSame(
            ['Discovery', 'Design', 'Build', 'Content', 'Launch'],
            $this->tenant(fn () => $copy->phases()->pluck('name')->all()),
        );
    }

    #[Test]
    public function the_browser_knows_every_operator_the_server_does(): void
    {
        // A key missing from the TypeScript copy is dropped whenever a chip rebuilds
        // the query, so `phase:Design` would vanish the moment somebody clicked one.
        preg_match('/export const KEYS = \[(.*?)\];/s', file_get_contents(resource_path('js/lib/issue-query.ts')), $match);
        preg_match_all("/'([a-z]+)'/", $match[1] ?? '', $keys);

        $this->assertSame(IssueQuery::KEYS, $keys[1]);
    }

    private function issue(string $title, ?Phase $phase = null, ?int $from = null, ?int $to = null, string $visibility = 'internal', bool $done = false): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle(
            $this->project,
            ['title' => $title, 'visibility' => $visibility],
            $this->owner,
        ));

        $issue->forceFill([
            'phase_id' => $phase?->id,
            'start_on' => $from === null ? null : now()->addDays($from)->toDateString(),
            'due_on' => $to === null ? null : now()->addDays($to)->toDateString(),
            'status_id' => $done
                ? $this->tenant(fn () => $this->project->statuses()->where('category', StatusCategory::Done->value)->firstOrFail()->id)
                : $issue->status_id,
        ])->save();

        return $this->reload($issue);
    }

    private function client(): User
    {
        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        return $client;
    }

    /** @return Collection<int, Phase> */
    private function phases(): Collection
    {
        return $this->tenant(fn () => $this->project->phases()->get());
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::findOrFail($issue->id));
    }

    private function projectUrl(string $suffix): string
    {
        return $this->workspaceUrl($this->workspace, "/projects/{$this->project->slug}{$suffix}");
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
