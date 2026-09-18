<?php

namespace Tests\Feature;

use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agency shape: one workspace, several client projects, a different client on
 * each, and none of them aware of the others.
 */
class MultiClientAccessTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $world = [];

    use RefreshDatabase;

    /**
     * Two client projects and one internal one; a client on each of the first two.
     */
    private function agency(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $clients = [];

        foreach (['northwind' => 'jo@northwind.test', 'globex' => 'sam@globex.test'] as $name => $email) {
            $user = User::factory()->create(['email' => $email]);
            $workspace->members()->attach($user->id, [
                'role' => WorkspaceRole::Client->value,
                'joined_at' => now(),
            ]);
            $clients[$name] = $user;
        }

        [$projects, $issues] = app(Tenancy::class)->run($workspace, function () use ($clients) {
            $projects = [
                'northwind' => Project::factory()->create(['name' => 'Northwind Site', 'key' => 'NW']),
                'globex' => Project::factory()->create(['name' => 'Globex Portal', 'key' => 'GX']),
                'internal' => Project::factory()->create(['name' => 'Our Own Tools', 'key' => 'INT']),
            ];

            // Each client is granted exactly one project.
            $projects['northwind']->clients()->attach($clients['northwind']->id, ['role' => 'client']);
            $projects['globex']->clients()->attach($clients['globex']->id, ['role' => 'client']);

            $issues = [];

            foreach (['northwind', 'globex'] as $name) {
                $issues[$name.'_shared'] = Issue::factory()->clientVisible()->create([
                    'project_id' => $projects[$name]->id,
                    'title' => ucfirst($name).' can see this',
                ]);

                $issues[$name.'_internal'] = Issue::factory()->create([
                    'project_id' => $projects[$name]->id,
                    'title' => ucfirst($name).' must not see this',
                    'visibility' => IssueVisibility::Internal->value,
                ]);
            }

            $issues['internal'] = Issue::factory()->clientVisible()->create([
                'project_id' => $projects['internal']->id,
                'title' => 'Nobody outside the team sees this',
            ]);

            return [$projects, $issues];
        });

        $this->world = compact('workspace', 'staff', 'clients', 'projects', 'issues');
    }

    /** @return array<int, string> */
    private function issueTitlesFor(User $user): array
    {
        $response = $this->actingAs($user)
            ->get($this->workspaceUrl($this->world['workspace'], '/issues?q=is%3Aany'))
            ->assertOk();

        return collect($response->viewData('page')['props']['issues'])
            ->pluck('title')->sort()->values()->all();
    }

    #[Test]
    public function each_client_sees_only_their_own_projects_shared_issues(): void
    {
        $this->agency();

        $this->assertSame(
            ['Northwind can see this'],
            $this->issueTitlesFor($this->world['clients']['northwind']),
        );

        $this->assertSame(
            ['Globex can see this'],
            $this->issueTitlesFor($this->world['clients']['globex']),
        );

        // Staff see everything in the workspace, across all three projects.
        $this->assertCount(5, $this->issueTitlesFor($this->world['staff']));
    }

    #[Test]
    public function being_in_the_project_is_not_enough_on_its_own(): void
    {
        $this->agency();

        // Northwind holds the Northwind project, but this issue was never marked
        // client-visible. Project access and issue visibility are separate gates and
        // both must be open.
        $this->actingAs($this->world['clients']['northwind'])
            ->get($this->workspaceUrl(
                $this->world['workspace'],
                '/issues/'.$this->world['issues']['northwind_internal']->key,
            ))
            ->assertNotFound();
    }

    #[Test]
    public function one_clients_issue_is_invisible_to_another_client(): void
    {
        $this->agency();

        // Client-visible, but in somebody else's project.
        $this->actingAs($this->world['clients']['globex'])
            ->get($this->workspaceUrl(
                $this->world['workspace'],
                '/issues/'.$this->world['issues']['northwind_shared']->key,
            ))
            ->assertNotFound();
    }

    #[Test]
    public function a_client_can_hold_more_than_one_project(): void
    {
        $this->agency();

        // Nothing restricts a client to a single project — they see the union.
        app(Tenancy::class)->run($this->world['workspace'], function () {
            $this->world['projects']['globex']->clients()
                ->attach($this->world['clients']['northwind']->id, ['role' => 'client']);
        });

        $this->assertSame(
            ['Globex can see this', 'Northwind can see this'],
            $this->issueTitlesFor($this->world['clients']['northwind']),
        );
    }

    #[Test]
    public function clients_cannot_reach_staff_only_surfaces(): void
    {
        $this->agency();

        $client = $this->world['clients']['northwind'];
        $workspace = $this->world['workspace'];

        foreach (['/inbox', '/settings/members', '/labels'] as $path) {
            $this->actingAs($client)
                ->get($this->workspaceUrl($workspace, $path))
                ->assertForbidden();
        }

        // And they cannot change the state of an issue they can see.
        $this->actingAs($client)
            ->patch($this->workspaceUrl(
                $workspace,
                '/issues/'.$this->world['issues']['northwind_shared']->key,
            ), ['priority' => 4])
            ->assertForbidden();
    }

    #[Test]
    public function a_client_never_learns_another_clients_project_exists(): void
    {
        $this->agency();

        $client = $this->world['clients']['northwind'];
        $workspace = $this->world['workspace'];

        // An agency runs several clients in one workspace. Northwind knowing that
        // "Globex Portal" is a customer is a leak even though they can read none of
        // the work in it.
        foreach (['/', '/projects', '/issues'] as $path) {
            $payload = json_encode(
                $this->actingAs($client)
                    ->get($this->workspaceUrl($workspace, $path))
                    ->assertOk()
                    ->viewData('page')['props'],
            );

            $this->assertStringNotContainsString('Globex Portal', $payload, "leaked via {$path}");
            $this->assertStringNotContainsString('Our Own Tools', $payload, "leaked via {$path}");
            $this->assertStringContainsString('Northwind Site', $payload, "own project missing from {$path}");
        }
    }

    #[Test]
    public function the_filter_bar_offers_a_client_nothing_they_should_not_see(): void
    {
        $this->agency();

        $workspace = $this->world['workspace'];

        // Give the other client's issue a label, so the label list could leak it.
        app(Tenancy::class)->run($workspace, function () {
            $label = \App\Models\Label::create(['name' => 'globex-only', 'color' => '#ef4444']);
            $this->world['issues']['globex_shared']->labels()->attach($label);

            $mine = \App\Models\Label::create(['name' => 'northwind-ok', 'color' => '#10b981']);
            $this->world['issues']['northwind_shared']->labels()->attach($mine);
        });

        $facets = $this->actingAs($this->world['clients']['northwind'])
            ->get($this->workspaceUrl($workspace, '/issues'))
            ->assertOk()
            ->viewData('page')['props']['facets'];

        $this->assertSame(['northwind-ok'], collect($facets['labels'])->pluck('name')->all());
        $this->assertSame(['Northwind Site'], collect($facets['projects'])->pluck('name')->all());
        // Clients cannot assign, so the staff list is just names they have not met.
        $this->assertCount(0, $facets['members']);
    }

    #[Test]
    public function shared_views_belong_to_the_team(): void
    {
        $this->agency();

        app(Tenancy::class)->run($this->world['workspace'], function () {
            \App\Models\SavedView::create([
                'name' => 'Globex escalations',
                'query' => 'is:open project:globex-portal',
                'user_id' => null,
                'created_by_id' => $this->world['staff']->id,
            ]);
        });

        // The name alone names another customer.
        $payload = json_encode(
            $this->actingAs($this->world['clients']['northwind'])
                ->get($this->workspaceUrl($this->world['workspace'], '/issues'))
                ->viewData('page')['props'],
        );

        $this->assertStringNotContainsString('Globex escalations', $payload);

        // Staff still see it.
        $staffPayload = json_encode(
            $this->actingAs($this->world['staff'])
                ->get($this->workspaceUrl($this->world['workspace'], '/issues'))
                ->viewData('page')['props'],
        );

        $this->assertStringContainsString('Globex escalations', $staffPayload);
    }

    #[Test]
    public function removing_a_project_grant_removes_the_access(): void
    {
        $this->agency();

        app(Tenancy::class)->run($this->world['workspace'], function () {
            $this->world['projects']['northwind']->clients()
                ->detach($this->world['clients']['northwind']->id);
        });

        $this->assertSame([], $this->issueTitlesFor($this->world['clients']['northwind']));
    }
}
