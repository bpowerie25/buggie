<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Actions\UpdateIssue;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where an issue starts, depending on who raised it.
 *
 * A client's issue used to start in the project's default status — the team's ready
 * queue, "Scoped" on the client website template — as if somebody had already looked
 * at it. It now waits in "New" and is listed on the Triage screen. Staff are triaging
 * as they file, so theirs still start in the default.
 */
class ClientIssueTriageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'acme');

        $this->client = User::factory()->create(['name' => 'Kennco Client']);
        $this->workspace->members()->attach($this->client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);

        // The template the report came from: its default status is "Scoped".
        $this->project = $this->tenant(fn () => app(CreateProject::class)->handle([
            'name' => 'Kennco Site', 'key' => 'KEN', 'template' => 'client_website',
        ]));
        $this->project->clients()->attach($this->client->id, ['role' => 'client']);
    }

    #[Test]
    public function a_client_raised_issue_starts_in_new_and_appears_in_triage(): void
    {
        $this->assertSame('Scoped', $this->project->defaultStatus()->name, 'The fixture should reproduce the report.');

        $issue = $this->file($this->client, 'The contact form does not send');

        $this->assertTrue($issue->status->is_triage);
        $this->assertSame('New', $issue->status->name);

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/inbox'))
            ->assertInertia(fn ($page) => $page
                ->has('clientIssues', 1)
                ->where('clientIssues.0.key', $issue->key)
                ->where('clientIssues.0.reporter', 'Kennco Client')
                ->where('inboxCount', 1));

        // And the client still sees what they filed.
        $this->actingAs($this->client)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertOk();
    }

    #[Test]
    public function a_client_cannot_choose_where_their_issue_starts(): void
    {
        $done = $this->project->statuses()->where('category', 'done')->firstOrFail();

        $issue = $this->file($this->client, 'Mark this done please', ['status_id' => $done->id]);

        $this->assertTrue($issue->status->is_triage);
    }

    #[Test]
    public function a_staff_raised_issue_keeps_the_project_default_and_skips_triage(): void
    {
        $issue = $this->file($this->owner, 'Swap the hero image');

        $this->assertSame('Scoped', $issue->status->name);

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/inbox'))
            ->assertInertia(fn ($page) => $page->has('clientIssues', 0));
    }

    #[Test]
    public function moving_it_out_of_new_takes_it_off_triage(): void
    {
        $issue = $this->file($this->client, 'The contact form does not send');
        $scoped = $this->project->defaultStatus();

        $this->tenant(fn () => app(UpdateIssue::class)->handle($issue, ['status_id' => $scoped->id], $this->owner));

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/inbox'))
            ->assertInertia(fn ($page) => $page->has('clientIssues', 0)->where('inboxCount', 0));
    }

    #[Test]
    public function a_project_whose_team_deleted_new_falls_back_to_its_default(): void
    {
        $this->project->triageStatus()->delete();

        $issue = $this->file($this->client, 'The contact form does not send');

        $this->assertSame('Scoped', $issue->status->name);
    }

    #[Test]
    public function staff_cannot_start_an_issue_in_another_projects_status(): void
    {
        $other = $this->tenant(fn () => Project::factory()->create(['key' => 'OTH']));
        $foreign = $other->statuses()->firstOrFail();

        $this->actingAs($this->owner)
            ->post($this->workspaceUrl($this->workspace, '/issues'), $this->form('Wrong workflow', ['status_id' => $foreign->id]))
            ->assertStatus(422);

        $this->assertSame(0, $this->tenant(fn () => Issue::count()));
    }

    #[Test]
    public function every_template_and_copy_gives_a_project_exactly_one_new(): void
    {
        foreach (array_keys(config('templates.templates')) as $key) {
            $project = $this->tenant(fn () => app(CreateProject::class)->handle(['name' => "From {$key}", 'template' => $key]));

            $triage = $project->statuses()->where('is_triage', true)->get();
            $this->assertCount(1, $triage, "[{$key}] should have one triage status.");
            $this->assertSame(0, $triage->first()->position, "[{$key}] New should come first.");
            $this->assertTrue($triage->first()->category->isOpen());
        }

        // A source whose team deleted New still yields a copy with one.
        $this->project->triageStatus()->delete();
        $copy = $this->tenant(fn () => app(CreateProject::class)->handle(['name' => 'Copy', 'source_project_id' => $this->project->id]));

        $this->assertSame(1, $copy->statuses()->where('is_triage', true)->count());
    }

    #[Test]
    public function the_upgrade_gives_every_existing_project_a_new_at_the_top(): void
    {
        $migration = require database_path('migrations/2026_09_24_120000_add_triage_status_to_projects.php');
        $clash = $this->tenant(fn () => Project::factory()->create(['key' => 'CLS']));

        // Back to how an install looked before: no flag, no New.
        $migration->down();
        $this->assertSame(0, Status::withoutGlobalScopes()->where('name', 'New')->count());

        // A team that already made its own "New", meaning something else.
        DB::table('statuses')->where('project_id', $clash->id)->where('name', 'Backlog')->update(['name' => 'New']);

        $migration->up();

        foreach ([$this->project, $clash] as $project) {
            $statuses = DB::table('statuses')->where('project_id', $project->id)->orderBy('position')->get();

            $this->assertTrue((bool) $statuses->first()->is_triage);
            $this->assertSame(1, $statuses->where('is_triage', true)->count());
            $this->assertSame($statuses->count(), $statuses->pluck('position')->unique()->count(), 'Positions collided.');
        }

        $this->assertSame('New', DB::table('statuses')->where('project_id', $this->project->id)->where('is_triage', true)->value('name'));
        $this->assertSame('Untriaged', DB::table('statuses')->where('project_id', $clash->id)->where('is_triage', true)->value('name'));
    }

    private function file(User $as, string $title, array $extra = []): Issue
    {
        $this->actingAs($as)
            ->post($this->workspaceUrl($this->workspace, '/issues'), $this->form($title, $extra))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->tenant(fn () => Issue::with('status')->where('title', $title)->sole());
    }

    /** @return array<string, mixed> */
    private function form(string $title, array $extra = []): array
    {
        return [
            'project_id' => $this->project->id,
            'title' => $title,
            'type' => 'bug',
            'priority' => 0,
            'visibility' => 'internal',
            ...$extra,
        ];
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
