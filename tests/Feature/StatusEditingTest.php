<?php

namespace Tests\Feature;

use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editing a workflow is where a customer's vocabulary meets a fixed invariant: names
 * are theirs, categories are ours, and "is this issue open?" has to keep working
 * whatever anybody calls their columns.
 */
class StatusEditingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\User, 2: Project} */
    private function project(): array
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::factory()->create(['key' => 'WEB']),
        );

        return [$workspace, $owner, $project];
    }

    #[Test]
    public function a_status_can_be_added_renamed_and_recoloured(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/statuses"), [
                'name' => 'Awaiting client',
                'category' => StatusCategory::Started->value,
                'color' => '#f59e0b',
            ])
            ->assertRedirect();

        $status = app(Tenancy::class)->run(
            $workspace,
            fn () => Status::where('name', 'Awaiting client')->firstOrFail(),
        );

        $this->assertSame(StatusCategory::Started, $status->category);
        $this->assertTrue($status->category->isOpen());

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                'name' => 'Waiting on client',
                'color' => '#8b5cf6',
            ])
            ->assertRedirect();

        $this->assertSame('Waiting on client', $status->fresh()->name);
        $this->assertSame('#8b5cf6', $status->fresh()->color);
    }

    #[Test]
    public function the_category_cannot_be_changed_after_creation(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $status = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('category', 'started')->firstOrFail(),
        );

        // Flipping a category rewrites history: issues that closed under it would
        // silently reopen, or the reverse.
        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                'name' => $status->name,
                'color' => $status->color,
                'category' => StatusCategory::Done->value,
            ])
            ->assertSessionHasErrors('category');

        $this->assertSame(StatusCategory::Started, $status->fresh()->category);
    }

    #[Test]
    public function deleting_a_status_in_use_requires_somewhere_to_put_the_issues(): void
    {
        [$workspace, $owner, $project] = $this->project();

        [$doomed, $keep] = app(Tenancy::class)->run($workspace, function () use ($project) {
            $doomed = $project->statuses()->where('category', 'started')->first();
            $keep = $project->statuses()->where('name', 'Todo')->first();

            Issue::factory()->count(3)->create([
                'project_id' => $project->id,
                'status_id' => $doomed->id,
            ]);

            return [$doomed, $keep];
        });

        // No target given, so nothing happens — issues are not cascaded away.
        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/statuses/{$doomed->id}"))
            ->assertSessionHasErrors('move_to');

        $this->assertNotNull($doomed->fresh());

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/statuses/{$doomed->id}"), [
                'move_to' => $keep->id,
            ])
            ->assertRedirect();

        $this->assertNull($doomed->fresh());

        app(Tenancy::class)->run($workspace, function () use ($keep) {
            $this->assertSame(3, Issue::where('status_id', $keep->id)->count());
            $this->assertSame(0, Issue::whereNull('status_id')->count());
        });
    }

    #[Test]
    public function an_unused_status_goes_without_ceremony(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $status = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('name', 'In Review')->firstOrFail(),
        );

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/statuses/{$status->id}"))
            ->assertRedirect();

        $this->assertNull($status->fresh());
    }

    #[Test]
    public function the_last_open_status_cannot_be_removed(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project) {
            // Leave exactly one open status standing.
            $project->statuses()->open()->orderBy('position')->get()->skip(1)
                ->each(fn (Status $s) => $s->delete());
        });

        $last = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->open()->firstOrFail(),
        );

        // New issues have to start somewhere.
        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/statuses/{$last->id}"))
            ->assertSessionHasErrors('status');

        $this->assertNotNull($last->fresh());
    }

    #[Test]
    public function exactly_one_status_is_the_default_and_it_must_be_open(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $done = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('category', 'done')->firstOrFail(),
        );

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$done->id}"), [
                'name' => $done->name,
                'color' => $done->color,
                'is_default' => true,
            ])
            ->assertSessionHasErrors('is_default');

        $inProgress = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('name', 'In Progress')->firstOrFail(),
        );

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/statuses/{$inProgress->id}"), [
                'name' => $inProgress->name,
                'color' => $inProgress->color,
                'is_default' => true,
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($project, $inProgress) {
            $defaults = $project->statuses()->where('is_default', true)->get();

            $this->assertCount(1, $defaults);
            $this->assertSame($inProgress->id, $defaults->first()->id);
        });
    }

    #[Test]
    public function deleting_the_default_promotes_another_open_status(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $default = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('is_default', true)->firstOrFail(),
        );

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/statuses/{$default->id}"))
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($project) {
            $now = $project->statuses()->where('is_default', true)->get();

            // A project without a default cannot create an issue at all.
            $this->assertCount(1, $now);
            $this->assertTrue($now->first()->category->isOpen());
        });
    }

    #[Test]
    public function names_are_unique_within_a_project_but_not_across_them(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/statuses"), [
                'name' => 'Todo',
                'category' => StatusCategory::Unstarted->value,
                'color' => '#64748b',
            ])
            ->assertSessionHasErrors('name');

        $other = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::factory()->create(['key' => 'APP']),
        );

        // Another project already has its own "Todo" and that is fine.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/projects/{$other->slug}/statuses"), [
                'name' => 'Awaiting client',
                'category' => StatusCategory::Started->value,
                'color' => '#f59e0b',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function reordering_only_touches_this_projects_statuses(): void
    {
        [$workspace, $owner, $project] = $this->project();

        [$ids, $foreign] = app(Tenancy::class)->run($workspace, function () use ($project) {
            $foreign = Project::factory()->create(['key' => 'APP'])->statuses()->first();

            return [
                $project->statuses()->orderBy('position')->pluck('id')->reverse()->values()->all(),
                $foreign,
            ];
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/statuses/order"), [
                'ids' => [...$ids, $foreign->id],
            ])
            ->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($project, $ids, $foreign) {
            $this->assertSame(
                $ids,
                $project->statuses()->orderBy('position')->pluck('id')->all(),
            );

            // The id belonging to another project was ignored, not reordered.
            $this->assertSame(0, $foreign->fresh()->position);
        });
    }

    #[Test]
    public function only_people_who_can_manage_projects_may_edit_the_workflow(): void
    {
        [$workspace, , $project] = $this->project();

        [$member] = $this->workspaceWithMember(WorkspaceRole::Member, 'globex');

        $status = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->firstOrFail(),
        );

        foreach ([WorkspaceRole::Member, WorkspaceRole::Client] as $role) {
            $user = \App\Models\User::factory()->create();
            $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

            $this->actingAs($user)
                ->patch($this->workspaceUrl($workspace, "/statuses/{$status->id}"), [
                    'name' => 'Renamed by someone who should not',
                    'color' => '#ffffff',
                ])
                ->assertForbidden();
        }

        $this->assertNotSame('Renamed by someone who should not', $status->fresh()->name);
        unset($member);
    }

    #[Test]
    public function closing_still_follows_the_category_after_renaming(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $done = app(Tenancy::class)->run(
            $workspace,
            fn () => $project->statuses()->where('category', 'done')->firstOrFail(),
        );

        $this->actingAs($owner)->patch($this->workspaceUrl($workspace, "/statuses/{$done->id}"), [
            'name' => 'Shipped',
            'color' => '#10b981',
        ]);

        app(Tenancy::class)->run($workspace, function () use ($project, $done, $owner) {
            $issue = Issue::factory()->create(['project_id' => $project->id]);

            app(\App\Actions\UpdateIssue::class)->handle($issue, ['status_id' => $done->id], $owner);

            // The name changed; the meaning did not.
            $this->assertNotNull($issue->fresh()->closed_at);
            $this->assertFalse($issue->fresh()->isOpen());
        });
    }
}
