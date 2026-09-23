<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two kinds of client.
 *
 * "Client" used to mean one thing: everybody granted a project saw every issue in it
 * marked client-visible. That quietly assumed one client per project. An agency's
 * client is usually whoever reported the bug, and only occasionally a project manager
 * whose job is to see all of it.
 *
 * Both halves are tested twice over, because the rule is expressed twice — once as a
 * SQL scope for lists and once in the policy for a single issue — and two expressions
 * of one rule is exactly where a leak hides.
 */
class ClientTierTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{workspace: \App\Models\Workspace, staff: User, project: Project, theirs: Issue, other: Issue} */
    private function scene(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        [$project, $theirs, $other] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(CreateProject::class)->handle(['name' => 'Site']);

            return [
                $project,
                // Filed by the client themselves, further down.
                null,
                app(CreateIssue::class)->handle($project, [
                    'title' => 'SOMEBODY-ELSES-TICKET', 'visibility' => 'client',
                ], $staff),
            ];
        });

        return compact('workspace', 'staff', 'project', 'theirs', 'other');
    }

    private function client(array $scene, ProjectRole $tier): User
    {
        $client = User::factory()->create();

        $scene['workspace']->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $scene['project']->clients()->attach($client->id, ['role' => $tier->value]);

        return $client;
    }

    private function fileAs(array $scene, User $client): Issue
    {
        return app(Tenancy::class)->run(
            $scene['workspace'],
            fn () => app(CreateIssue::class)->handle($scene['project'], [
                'title' => 'MY-OWN-TICKET',
            ], $client),
        );
    }

    // ------------------------------------------------------------- the plain tier

    #[Test]
    public function a_client_sees_their_own_ticket_and_not_somebody_elses(): void
    {
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::Client);
        $mine = $this->fileAs($scene, $client);

        $this->actingAs($client)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            ->assertSee('MY-OWN-TICKET')
            ->assertDontSee('SOMEBODY-ELSES-TICKET');
    }

    #[Test]
    public function the_policy_agrees_with_the_list_for_a_plain_client(): void
    {
        // The same rule, expressed the other way. If these two ever disagree, one of
        // them is a leak.
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::Client);
        $mine = $this->fileAs($scene, $client);

        app(Tenancy::class)->run($scene['workspace'], function () use ($client, $mine, $scene) {
            $this->assertTrue(Gate::forUser($client)->allows('view', $mine));
            $this->assertFalse(Gate::forUser($client)->allows('view', $scene['other']));
        });

        // And over HTTP, where a refusal must read as "not found" rather than
        // confirming the issue exists.
        $this->actingAs($client)
            ->get($this->workspaceUrl($scene['workspace'], "/issues/{$scene['other']->key}"))
            ->assertNotFound();
    }

    #[Test]
    public function being_brought_into_an_issue_is_enough(): void
    {
        // Commenting or being mentioned makes somebody a watcher, and a client who is
        // part of a conversation should not lose sight of it.
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::Client);

        app(Tenancy::class)->run($scene['workspace'], fn () => $scene['other']
            ->watch($client, \App\Enums\WatchReason::Mentioned));

        $this->actingAs($client)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            ->assertSee('SOMEBODY-ELSES-TICKET');
    }

    // ----------------------------------------------------------- the manager tier

    #[Test]
    public function a_client_manager_sees_the_whole_client_side_of_the_project(): void
    {
        $scene = $this->scene();
        $manager = $this->client($scene, ProjectRole::ClientManager);

        $this->actingAs($manager)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            ->assertSee('SOMEBODY-ELSES-TICKET');

        app(Tenancy::class)->run($scene['workspace'], function () use ($manager, $scene) {
            $this->assertTrue(Gate::forUser($manager)->allows('view', $scene['other']));
        });
    }

    #[Test]
    public function a_manager_still_never_sees_internal_work(): void
    {
        // The tier decides how much of the client-visible half somebody sees. It has
        // nothing to say about the internal half, and must not acquire an opinion.
        $scene = $this->scene();
        $manager = $this->client($scene, ProjectRole::ClientManager);

        $internal = app(Tenancy::class)->run(
            $scene['workspace'],
            fn () => app(CreateIssue::class)->handle($scene['project'], [
                'title' => 'INTERNAL-ONLY-TICKET', 'visibility' => 'internal',
            ], $scene['staff']),
        );

        $this->actingAs($manager)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            ->assertDontSee('INTERNAL-ONLY-TICKET')
            ->assertSee('SOMEBODY-ELSES-TICKET');

        $this->actingAs($manager)
            ->get($this->workspaceUrl($scene['workspace'], "/issues/{$internal->key}"))
            ->assertNotFound();
    }

    #[Test]
    public function the_tier_is_per_project_not_per_person(): void
    {
        // Somebody can manage one client's project and be an ordinary reporter on
        // another. The grant is the unit, not the account.
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::ClientManager);

        $second = app(Tenancy::class)->run($scene['workspace'], function () use ($scene) {
            $project = app(CreateProject::class)->handle(['name' => 'Second']);

            app(CreateIssue::class)->handle($project, [
                'title' => 'OTHER-PROJECT-TICKET', 'visibility' => 'client',
            ], $scene['staff']);

            return $project;
        });

        $second->clients()->attach($client->id, ['role' => ProjectRole::Client->value]);

        $this->actingAs($client)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            // Manager on the first project.
            ->assertSee('SOMEBODY-ELSES-TICKET')
            // Ordinary client on the second, and this one is not theirs.
            ->assertDontSee('OTHER-PROJECT-TICKET');
    }

    // ------------------------------------------------------------------ defaults

    #[Test]
    public function an_unknown_or_missing_tier_is_treated_as_the_most_restrictive(): void
    {
        /*
         * The column is free text and predates this, so a row could hold "maintainer"
         * or anything else. Failing open would be a leak; failing to the narrowest
         * tier is the only safe reading of a value nobody recognises.
         */
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::Client);

        \Illuminate\Support\Facades\DB::table('project_user')
            ->where('user_id', $client->id)
            ->update(['role' => 'something-nobody-defined']);

        $this->actingAs($client)
            ->get($this->workspaceUrl($scene['workspace'], '/issues'))
            ->assertOk()
            ->assertDontSee('SOMEBODY-ELSES-TICKET');

        app(Tenancy::class)->run($scene['workspace'], function () use ($client, $scene) {
            $this->assertFalse(Gate::forUser($client)->allows('view', $scene['other']));
        });
    }

    #[Test]
    public function granting_projects_defaults_to_the_narrow_tier(): void
    {
        // The tier somebody gets without thinking about it has to be the safe one.
        $scene = $this->scene();
        $client = $this->client($scene, ProjectRole::Client);

        $this->actingAs($scene['staff']);
        $scene['workspace']->members()->updateExistingPivot(
            $scene['staff']->id,
            ['role' => WorkspaceRole::Owner->value],
        );

        $this->patch(
            $this->workspaceUrl($scene['workspace'], "/settings/members/{$client->id}/projects"),
            ['project_ids' => [$scene['project']->id]],
        )->assertRedirect();

        $this->assertSame(
            ProjectRole::Client->value,
            \Illuminate\Support\Facades\DB::table('project_user')
                ->where('user_id', $client->id)->value('role'),
        );
    }

    #[Test]
    public function editing_which_projects_a_manager_sees_does_not_demote_them(): void
    {
        /*
         * The trap this feature walks into. The grants screen rewrote every row as
         * "client" on save, so changing which projects somebody could see would have
         * silently narrowed what they could see inside them — a permission changing
         * because an unrelated checkbox moved.
         */
        $scene = $this->scene();
        $manager = $this->client($scene, ProjectRole::ClientManager);

        $this->actingAs($scene['staff']);
        $scene['workspace']->members()->updateExistingPivot(
            $scene['staff']->id,
            ['role' => WorkspaceRole::Owner->value],
        );

        $this->patch(
            $this->workspaceUrl($scene['workspace'], "/settings/members/{$manager->id}/projects"),
            ['project_ids' => [$scene['project']->id]],
        )->assertRedirect();

        $this->assertSame(
            ProjectRole::ClientManager->value,
            \Illuminate\Support\Facades\DB::table('project_user')
                ->where('user_id', $manager->id)->value('role'),
            'Editing the project list demoted a client manager.',
        );

        // And it can still be changed deliberately.
        $this->patch(
            $this->workspaceUrl($scene['workspace'], "/settings/members/{$manager->id}/projects"),
            [
                'project_ids' => [$scene['project']->id],
                'tiers' => [$scene['project']->id => ProjectRole::Client->value],
            ],
        )->assertRedirect();

        $this->assertSame(
            ProjectRole::Client->value,
            \Illuminate\Support\Facades\DB::table('project_user')
                ->where('user_id', $manager->id)->value('role'),
        );
    }


    #[Test]
    public function the_members_screen_says_which_tier_each_grant_carries(): void
    {
        // Without this the editor opens showing "their own issues" for a manager and
        // saving would demote them — the screen has to know what it is editing.
        $scene = $this->scene();
        $manager = $this->client($scene, ProjectRole::ClientManager);

        $scene['workspace']->members()->updateExistingPivot(
            $scene['staff']->id,
            ['role' => WorkspaceRole::Owner->value],
        );

        $this->actingAs($scene['staff'])
            ->get($this->workspaceUrl($scene['workspace'], '/settings/members'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has(
                'members',
                fn ($members) => $members->each(fn ($m) => $m->has('tiers')->etc()),
            ));
    }
}
