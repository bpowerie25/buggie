<?php

namespace Tests\Feature;

use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VersionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Project} */
    private function project(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        return [$workspace, $staff, $project];
    }

    #[Test]
    public function a_release_is_created_and_starts_unreleased(): void
    {
        [$workspace, $staff, $project] = $this->project();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions"), [
                'name' => '2.4.1',
            ])
            ->assertRedirect();

        $version = app(Tenancy::class)->run($workspace, fn () => Version::firstOrFail());

        $this->assertSame('2.4.1', $version->name);
        $this->assertFalse($version->isReleased());
    }

    #[Test]
    public function two_releases_cannot_share_a_name_within_a_project(): void
    {
        [$workspace, $staff, $project] = $this->project();

        app(Tenancy::class)->run($workspace, fn () => Version::factory()->create([
            'project_id' => $project->id,
            'name' => '2.4.1',
        ]));

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions"), [
                'name' => '2.4.1',
            ])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function two_projects_may_each_have_their_own_2_4_1(): void
    {
        // The control: uniqueness is per project, not per workspace. Everybody's
        // software has a 2.4.1 eventually.
        [$workspace, $staff, $project] = $this->project();

        $other = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'APP']));

        foreach ([$project, $other] as $each) {
            $this->actingAs($staff)
                ->post($this->workspaceUrl($workspace, "/projects/{$each->slug}/versions"), [
                    'name' => '2.4.1',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, app(Tenancy::class)->run($workspace, fn () => Version::count()));
    }

    #[Test]
    public function releasing_stamps_the_date_and_unreleasing_clears_it(): void
    {
        // Both are ordinary: a release gets pulled, and a date typed by hand is a
        // date somebody gets wrong.
        [$workspace, $staff, $project] = $this->project();

        $version = app(Tenancy::class)->run($workspace, fn () => Version::factory()->create([
            'project_id' => $project->id,
        ]));

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions/{$version->id}"), [
                'released' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($version->fresh()->isReleased());

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions/{$version->id}"), [
                'released' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($version->fresh()->isReleased());
    }

    #[Test]
    public function deleting_a_release_keeps_its_issues(): void
    {
        // Deleting a release must never delete the work that was in it.
        [$workspace, $staff, $project] = $this->project();

        [$version, $issue] = app(Tenancy::class)->run($workspace, function () use ($project) {
            $version = Version::factory()->create(['project_id' => $project->id]);

            return [$version, Issue::factory()->create([
                'project_id' => $project->id,
                'version_id' => $version->id,
            ])];
        });

        $this->actingAs($staff)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions/{$version->id}"))
            ->assertRedirect();

        $fresh = $issue->fresh();

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->version_id);
    }

    #[Test]
    public function an_issue_cannot_be_put_in_another_projects_release(): void
    {
        // "2.4.1" is a release of one thing. An issue on the marketing site has no
        // business in the mobile app's release notes.
        [$workspace, $staff, $project] = $this->project();

        [$issue, $elsewhere] = app(Tenancy::class)->run($workspace, function () use ($project) {
            $other = Project::factory()->create(['key' => 'APP']);

            return [
                Issue::factory()->create(['project_id' => $project->id]),
                Version::factory()->create(['project_id' => $other->id]),
            ];
        });

        $this->expectException(ValidationException::class);

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['version_id' => $elsewhere->id], $staff));
    }

    #[Test]
    public function putting_an_issue_in_a_release_is_recorded(): void
    {
        [$workspace, $staff, $project] = $this->project();

        [$issue, $version] = app(Tenancy::class)->run($workspace, fn () => [
            Issue::factory()->create(['project_id' => $project->id]),
            Version::factory()->create(['project_id' => $project->id, 'name' => '2.4.1']),
        ]);

        app(Tenancy::class)->run($workspace, fn () => app(UpdateIssue::class)
            ->handle($issue, ['version_id' => $version->id], $staff));

        $event = $issue->fresh()->events()->where('type', IssueEventType::VersionChanged)->first();

        $this->assertNotNull($event);
        $this->assertSame('2.4.1', $event->data['to']);
    }

    #[Test]
    public function the_query_language_filters_by_release(): void
    {
        [$workspace, $staff, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project) {
            $version = Version::factory()->create(['project_id' => $project->id, 'name' => '2.4.1']);

            Issue::factory()->create([
                'project_id' => $project->id,
                'version_id' => $version->id,
                'title' => 'Went out in 2.4.1',
            ]);

            Issue::factory()->create(['project_id' => $project->id, 'title' => 'Not in a release']);
        });

        $shipped = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('is:any version:2.4.1')))
            ->assertOk();

        $titles = collect($shipped->viewData('page')['props']['issues'])->pluck('title');

        $this->assertContains('Went out in 2.4.1', $titles);
        $this->assertNotContains('Not in a release', $titles);
    }

    #[Test]
    public function no_version_finds_what_is_not_planned_yet(): void
    {
        // The list you work from when deciding what goes in the next release.
        [$workspace, $staff, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project) {
            $version = Version::factory()->create(['project_id' => $project->id]);

            Issue::factory()->create(['project_id' => $project->id, 'version_id' => $version->id, 'title' => 'Planned']);
            Issue::factory()->create(['project_id' => $project->id, 'title' => 'Unplanned']);
        });

        $response = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('is:any no:version')))
            ->assertOk();

        $titles = collect($response->viewData('page')['props']['issues'])->pluck('title');

        $this->assertContains('Unplanned', $titles);
        $this->assertNotContains('Planned', $titles);
    }

    #[Test]
    public function a_client_reading_a_changelog_sees_only_their_own_issues(): void
    {
        // The same visibility rules as everywhere else: a changelog is a listing, and
        // a listing is where a leak goes unnoticed.
        [$workspace, $staff, $project] = $this->project();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);
        $project->clients()->attach($client->id, ['role' => 'client_manager']);

        $version = app(Tenancy::class)->run($workspace, function () use ($project) {
            $version = Version::factory()->released()->create(['project_id' => $project->id]);

            Issue::factory()->clientVisible()->create([
                'project_id' => $project->id,
                'version_id' => $version->id,
                'title' => 'Fixed the checkout',
            ]);

            Issue::factory()->create([
                'project_id' => $project->id,
                'version_id' => $version->id,
                'title' => 'Rewrote our billing module',
            ]);

            return $version;
        });

        $html = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions/{$version->id}"))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fixed the checkout', $html);
        $this->assertStringNotContainsString('Rewrote our billing module', $html);
    }

    #[Test]
    public function a_release_from_another_project_is_not_found(): void
    {
        [$workspace, $staff, $project] = $this->project();

        $version = app(Tenancy::class)->run($workspace, function () {
            $other = Project::factory()->create(['key' => 'APP']);

            return Version::factory()->create(['project_id' => $other->id]);
        });

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/versions/{$version->id}"))
            ->assertNotFound();
    }
}
