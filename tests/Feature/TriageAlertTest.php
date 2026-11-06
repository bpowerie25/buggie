<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\ProcessIncomingReport;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportNeedsTriage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TriageAlertTest extends TestCase
{
    use RefreshDatabase;

    private function staff(object $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

        return $user;
    }

    #[Test]
    public function the_projects_triage_list_is_emailed_and_nobody_else(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $member = $this->staff($workspace, WorkspaceRole::Member);
        $other = $this->staff($workspace, WorkspaceRole::Member);
        $client = $this->staff($workspace, WorkspaceRole::Client);

        $report = app(Tenancy::class)->run($workspace, function () use ($member, $client) {
            $project = Project::factory()->create(['settings' => ['triage_recipients' => [$member->id, $client->id]]]);

            return Report::factory()->create(['project_id' => $project->id, 'error' => null]);
        });

        ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

        Notification::assertSentTo($member, ReportNeedsTriage::class);
        Notification::assertNotSentTo($other, ReportNeedsTriage::class);
        Notification::assertNotSentTo($client, ReportNeedsTriage::class);
        Notification::assertNotSentTo($owner, ReportNeedsTriage::class);
    }

    #[Test]
    public function an_unconfigured_project_falls_back_to_the_admins(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $member = $this->staff($workspace, WorkspaceRole::Member);

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->create([
            'project_id' => Project::factory()->create()->id,
            'error' => null,
        ]));

        ProcessIncomingReport::dispatchSync($report->id, $workspace->id);

        Notification::assertSentTo($owner, ReportNeedsTriage::class);
        Notification::assertNotSentTo($member, ReportNeedsTriage::class);
    }

    #[Test]
    public function an_admin_can_categorise_projects_and_a_stranger_cannot_use_the_category(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$globex] = $this->workspaceWithMember(slug: 'globex');

        $this->actingAs($owner)->post($this->workspaceUrl($workspace, '/project-categories'), [
            'name' => 'Design', 'color' => '#3b82f6',
        ])->assertRedirect();

        $category = app(Tenancy::class)->run($workspace, fn () => ProjectCategory::firstOrFail());
        $foreign = app(Tenancy::class)->run($globex, fn () => ProjectCategory::create(['name' => 'Theirs', 'color' => '#000000']));
        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create());

        $url = $this->workspaceUrl($workspace, "/projects/{$project->slug}");

        $this->actingAs($owner)->put($url, ['name' => $project->name, 'project_category_id' => $foreign->id])
            ->assertSessionHasErrors('project_category_id');

        $this->actingAs($owner)->put($url, ['name' => $project->name, 'project_category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($category->id, $project->fresh()->project_category_id);
    }
}
