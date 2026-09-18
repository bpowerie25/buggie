<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\IssuePriority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_an_issue_assigns_the_next_key_and_opens_the_feed(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::factory()->create(['key' => 'WEB']),
        );

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/issues'), [
                'project_id' => $project->id,
                'title' => 'Checkout button does nothing',
            ])
            ->assertRedirect();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::firstOrFail());

        $this->assertSame('WEB-1', $issue->key);
        $this->assertSame(1, $issue->number);
        $this->assertSame($user->id, $issue->reporter_id);
        $this->assertTrue($issue->status->category->isOpen());

        // The reporter watches what they filed, and the feed starts with 'created'.
        $this->assertTrue($issue->watchers->contains($user));
        $this->assertSame(IssueEventType::Created, $issue->events->first()->type);
    }

    #[Test]
    public function issue_numbers_continue_from_the_projects_sequence(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::factory()->create(['key' => 'WEB']),
        );

        foreach (['One', 'Two', 'Three'] as $title) {
            $this->actingAs($user)->post($this->workspaceUrl($workspace, '/issues'), [
                'project_id' => $project->id,
                'title' => $title,
            ]);
        }

        $keys = app(Tenancy::class)->run(
            $workspace,
            fn () => Issue::orderBy('number')->pluck('key')->all(),
        );

        $this->assertSame(['WEB-1', 'WEB-2', 'WEB-3'], $keys);
    }

    #[Test]
    public function closing_an_issue_is_driven_by_category_not_status_name(): void
    {
        [$workspace, $user] = $this->workspaceWithMember();

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = Project::factory()->create();
            $issue = Issue::factory()->create(['project_id' => $project->id]);

            $this->assertNull($issue->closed_at);

            // A status renamed by the customer still closes, because its category says so.
            $done = $project->statuses()->where('category', StatusCategory::Done->value)->first();
            $done->update(['name' => 'Shipped To Production']);

            app(UpdateIssue::class)->handle($issue, ['status_id' => $done->id], $user);
            $issue->refresh();

            $this->assertNotNull($issue->closed_at);
            $this->assertNotNull($issue->resolved_at);
            $this->assertFalse($issue->isOpen());

            // And reopening clears both, and records it.
            $todo = $project->statuses()->where('category', StatusCategory::Unstarted->value)->first();
            app(UpdateIssue::class)->handle($issue, ['status_id' => $todo->id], $user);
            $issue->refresh();

            $this->assertNull($issue->closed_at);
            $this->assertNull($issue->resolved_at);
            $this->assertTrue(
                $issue->events->contains(fn ($e) => $e->type === IssueEventType::Reopened),
            );
        });
    }

    #[Test]
    public function a_cancelled_issue_is_closed_but_not_resolved(): void
    {
        [$workspace, $user] = $this->workspaceWithMember();

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = Project::factory()->create();
            $issue = Issue::factory()->create(['project_id' => $project->id]);

            $wontFix = $project->statuses()
                ->where('category', StatusCategory::Canceled->value)->first();

            app(UpdateIssue::class)->handle($issue, ['status_id' => $wontFix->id], $user);
            $issue->refresh();

            $this->assertNotNull($issue->closed_at, 'Cancelled issues are closed.');
            $this->assertNull($issue->resolved_at, 'Cancelled is not the same as fixed.');
        });
    }

    #[Test]
    public function a_status_from_another_project_is_rejected(): void
    {
        [$workspace, $user] = $this->workspaceWithMember();

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $issue = Issue::factory()->create();
            $foreign = Project::factory()->create()->statuses()->first();

            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

            app(UpdateIssue::class)->handle($issue, ['status_id' => $foreign->id], $user);
        });
    }

    #[Test]
    public function only_fields_that_actually_change_produce_events(): void
    {
        [$workspace, $user] = $this->workspaceWithMember();

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $issue = Issue::factory()->create([
                'title' => 'Same',
                'priority' => IssuePriority::None->value,
            ]);
            $before = $issue->events()->count();

            // Writing identical values is a no-op, not four feed entries.
            app(UpdateIssue::class)->handle($issue, [
                'title' => 'Same',
                'priority' => IssuePriority::None->value,
                'status_id' => $issue->status_id,
                'assignee_id' => null,
            ], $user);

            $this->assertSame($before, $issue->events()->count());

            app(UpdateIssue::class)->handle($issue, ['priority' => IssuePriority::Urgent->value], $user);

            $this->assertSame($before + 1, $issue->events()->count());
        });
    }

    #[Test]
    public function search_matches_the_title_and_the_flattened_description(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($user) {
            $project = Project::factory()->create(['key' => 'WEB']);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Checkout is broken',
            ], $user);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Unrelated thing',
                'description' => [
                    'type' => 'doc',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'The stylesheet fails to load.']],
                    ]],
                ],
            ], $user);

            $this->assertSame(1, Issue::search('checkout')->count());
            $this->assertSame(1, Issue::search('stylesheet')->count());
            $this->assertSame(0, Issue::search('nonexistent')->count());

            // Typing a key jumps straight to that issue.
            $this->assertSame('WEB-1', Issue::search('web-1')->first()->key);
        });
    }

    #[Test]
    public function the_list_defaults_to_open_issues(): void
    {
        [$workspace, $user] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();
            Issue::factory()->create(['project_id' => $project->id, 'title' => 'Still open']);
            Issue::factory()->inStatus('done')->create([
                'project_id' => $project->id, 'title' => 'Finished',
            ]);
        });

        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('issues/index')
                ->has('issues', 1)
                ->where('issues.0.title', 'Still open'));

        $this->actingAs($user)
            ->get($this->workspaceUrl($workspace, '/issues?q=is%3Aany'))
            ->assertInertia(fn ($page) => $page->has('issues', 2));
    }

    #[Test]
    public function an_issue_from_another_workspace_is_unreachable_by_key(): void
    {
        [$acme, $acmeUser] = $this->workspaceWithMember(WorkspaceRole::Owner, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Owner, 'globex');

        $theirs = app(Tenancy::class)->run(
            $globex,
            fn () => Issue::factory()->create(['title' => 'Their secret']),
        );

        $this->actingAs($acmeUser)
            ->get($this->workspaceUrl($acme, '/issues/'.$theirs->key))
            ->assertNotFound();
    }

    #[Test]
    public function deleting_an_issue_requires_an_admin(): void
    {
        [$workspace, $member] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create());

        $this->actingAs($member)
            ->delete($this->workspaceUrl($workspace, '/issues/'.$issue->key))
            ->assertForbidden();
    }
}
