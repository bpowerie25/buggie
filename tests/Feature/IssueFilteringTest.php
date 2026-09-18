<?php

namespace Tests\Feature;

use App\Enums\IssuePriority;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The query language applied end to end. IssueQueryTest covers parsing; this covers
 * what actually comes back.
 */
class IssueFilteringTest extends TestCase
{
    use RefreshDatabase;

    private function seedWorkspace(): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $other = User::factory()->create(['name' => 'Sam Rivera']);
        $workspace->members()->attach($other->id, [
            'role' => \App\Enums\WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($owner, $other) {
            $web = Project::factory()->create(['key' => 'WEB', 'slug' => 'web', 'name' => 'Web']);
            $app = Project::factory()->create(['key' => 'APP', 'slug' => 'app', 'name' => 'App']);

            $regression = Label::create(['name' => 'regression', 'color' => '#ef4444']);
            $wontfix = Label::create(['name' => 'wontfix', 'color' => '#64748b']);

            $mine = Issue::factory()->create([
                'project_id' => $web->id,
                'title' => 'Checkout is broken',
                'assignee_id' => $owner->id,
                'priority' => IssuePriority::Urgent->value,
                'type' => 'bug',
            ]);
            $mine->labels()->attach($regression);

            $theirs = Issue::factory()->create([
                'project_id' => $web->id,
                'title' => 'Sidebar overlaps footer',
                'assignee_id' => $other->id,
                'priority' => IssuePriority::Low->value,
                'type' => 'bug',
            ]);
            $theirs->labels()->attach($wontfix);

            Issue::factory()->create([
                'project_id' => $app->id,
                'title' => 'Add dark mode',
                'assignee_id' => null,
                'priority' => IssuePriority::Medium->value,
                'type' => 'feature',
            ]);

            Issue::factory()->inStatus('done')->create([
                'project_id' => $web->id,
                'title' => 'Already finished',
            ]);
        });

        return [$workspace, $owner];
    }

    /** @return array<int, string> */
    private function titlesFor(string $query): array
    {
        [$workspace, $owner] = $this->state;

        $response = $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode($query)))
            ->assertOk();

        return collect($response->viewData('page')['props']['issues'])
            ->pluck('title')
            ->sort()
            ->values()
            ->all();
    }

    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_filters_by_every_supported_operator(): void
    {
        $this->state = $this->seedWorkspace();

        $this->assertSame(
            ['Add dark mode', 'Checkout is broken', 'Sidebar overlaps footer'],
            $this->titlesFor(''),
            'An empty query means open issues.',
        );

        $this->assertSame(['Already finished'], $this->titlesFor('is:closed'));
        $this->assertCount(4, $this->titlesFor('is:any'));

        $this->assertSame(['Checkout is broken'], $this->titlesFor('assignee:@me'));
        $this->assertSame(['Add dark mode'], $this->titlesFor('no:assignee'));
        $this->assertSame(['Sidebar overlaps footer'], $this->titlesFor('assignee:sam'));

        $this->assertSame(
            ['Checkout is broken', 'Sidebar overlaps footer'],
            $this->titlesFor('project:web'),
        );

        $this->assertSame(['Checkout is broken'], $this->titlesFor('label:regression'));
        $this->assertSame(['Add dark mode'], $this->titlesFor('type:feature'));
        $this->assertSame(['Checkout is broken'], $this->titlesFor('priority:urgent'));
    }

    #[Test]
    public function negation_excludes(): void
    {
        $this->state = $this->seedWorkspace();

        $this->assertSame(
            ['Add dark mode', 'Checkout is broken'],
            $this->titlesFor('-label:wontfix'),
        );

        $this->assertSame(['Add dark mode'], $this->titlesFor('-project:web'));
        $this->assertSame(['Add dark mode'], $this->titlesFor('-assignee:@me -assignee:sam'));
    }

    #[Test]
    public function operators_combine_and_free_text_searches(): void
    {
        $this->state = $this->seedWorkspace();

        $this->assertSame(
            ['Checkout is broken'],
            $this->titlesFor('is:open project:web assignee:@me label:regression'),
        );

        $this->assertSame(['Checkout is broken'], $this->titlesFor('checkout'));
        $this->assertSame(['Checkout is broken'], $this->titlesFor('project:web checkout'));
        $this->assertSame([], $this->titlesFor('project:app checkout'));
    }

    #[Test]
    public function an_unresolvable_person_matches_nothing_rather_than_everything(): void
    {
        $this->state = $this->seedWorkspace();

        // The dangerous failure is a filter that silently does nothing.
        $this->assertSame([], $this->titlesFor('assignee:nobodyhere'));
    }

    #[Test]
    public function a_client_still_only_sees_their_own_issues_whatever_the_query(): void
    {
        [$workspace, $owner] = $this->seedWorkspace();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => \App\Enums\WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        // A query that would match everything for staff.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues?q=is%3Aany'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('issues', 0));

        unset($owner);
    }

    #[Test]
    public function bulk_updates_apply_to_every_selected_issue(): void
    {
        [$workspace, $owner] = $this->seedWorkspace();

        $keys = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::open()->pluck('key')->all(),
        );

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, '/issues/bulk'), [
                'keys' => $keys,
                'changes' => ['priority' => IssuePriority::High->value],
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($keys) {
            foreach (Issue::whereIn('key', $keys)->get() as $issue) {
                $this->assertSame(IssuePriority::High, $issue->priority);
            }
        });
    }

    #[Test]
    public function bulk_updates_cannot_reach_another_workspace(): void
    {
        [$workspace, $owner] = $this->seedWorkspace();
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $theirs = app(Tenancy::class)->run(
            $globex,
            fn () => Issue::factory()->create(['priority' => IssuePriority::None->value]),
        );

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, '/issues/bulk'), [
                'keys' => [$theirs->key],
                'changes' => ['priority' => IssuePriority::Urgent->value],
            ])
            ->assertRedirect();

        // Scoped out entirely: the key resolves to nothing, so nothing changed.
        $this->assertSame(
            IssuePriority::None,
            app(Tenancy::class)->run($globex, fn () => $theirs->fresh()->priority),
        );
    }
}
