<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leave and public holidays on the workload screen, and the list of disciplines it
 * groups people by.
 */
class TimeOffAndDisciplinesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $dana;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 5 October 2026.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00'));

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->dana = User::factory()->create(['name' => 'Dana']);
        $this->workspace->members()->attach($this->dana->id, [
            'role' => WorkspaceRole::Member->value, 'joined_at' => now(), 'weekly_hours' => 20, 'discipline' => 'Developer',
        ]);
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));
    }

    #[Test]
    public function leave_takes_hours_out_of_the_week_and_work_is_not_planned_onto_it(): void
    {
        $this->book($this->dana, $this->dana, '2026-10-07', '2026-10-09')->assertSessionHasNoErrors();

        // Thursday to Tuesday. Thursday and Friday she is away, so it all lands next week.
        $this->issue('Build', $this->dana, '2026-10-08', '2026-10-13', 10);

        $dana = $this->person('Dana');

        $this->assertSame([3, 8 * 60], [$dana['cells']['2026-10-05']['off'], $dana['cells']['2026-10-05']['capacity']]);
        $this->assertSame(0, $dana['cells']['2026-10-05']['minutes']);
        $this->assertSame(10 * 60, $dana['cells']['2026-10-12']['minutes']);
        $this->assertSame(20 * 60, $dana['cells']['2026-10-12']['capacity']);
    }

    #[Test]
    public function a_public_holiday_is_off_for_everyone_and_work_on_it_moves_to_the_next_working_day(): void
    {
        $this->book($this->owner, null, '2026-10-09', '2026-10-09', 'Staff day')->assertSessionHasNoErrors();

        $this->issue('Due on the holiday', null, '2026-10-09', '2026-10-09', 6);

        $this->assertSame(16 * 60, $this->person('Dana')['cells']['2026-10-05']['capacity']);
        $this->assertSame(0, $this->page()['unassigned']['2026-10-05']);
        $this->assertSame(6 * 60, $this->page()['unassigned']['2026-10-12']);
    }

    #[Test]
    public function staff_book_their_own_leave_and_only_admins_book_anybody_elses_or_holidays(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->workspace->members()->attach($sam->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        $this->book($this->dana, $this->dana, '2026-10-20', '2026-10-21')->assertSessionHasNoErrors();
        $this->book($this->dana, $sam, '2026-10-20', '2026-10-21')->assertForbidden();
        $this->book($this->dana, null, '2026-12-25', '2026-12-25')->assertForbidden();
        $this->book($this->owner, $sam, '2026-10-20', '2026-10-21')->assertSessionHasNoErrors();

        $samsLeave = $this->tenant(fn () => TimeOff::where('user_id', $sam->id)->sole());
        $this->actingAs($this->dana)->delete($this->workspaceUrl($this->workspace, "/time-off/{$samsLeave->id}"))->assertForbidden();

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        $this->book($this->owner, $client, '2026-10-20', '2026-10-21')->assertSessionHasErrors('user_id');
        $this->book($client, $client, '2026-10-20', '2026-10-21')->assertNotFound();
    }

    #[Test]
    public function the_disciplines_list_is_edited_in_one_place_and_the_people_on_it_follow(): void
    {
        $url = $this->workspaceUrl($this->workspace, '/settings/disciplines');

        // Before anybody edits it: the defaults.
        $this->assertContains('Developer', $this->workspace->fresh()->disciplines());

        $this->actingAs($this->owner)->post($url, ['name' => 'Copywriter'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($url, ['name' => 'copywriter'])->assertSessionHasErrors('name');

        $this->actingAs($this->owner)->patch($url, ['from' => 'Developer', 'to' => 'Engineer'])->assertSessionHasNoErrors();
        $this->assertSame('Engineer', $this->workspace->members()->whereKey($this->dana->id)->first()->pivot->discipline);

        $list = $this->workspace->fresh()->disciplines();
        $this->actingAs($this->owner)->put($url.'/order', ['names' => array_reverse($list)])->assertSessionHasNoErrors();
        $this->assertSame(array_reverse($list), $this->workspace->fresh()->disciplines());

        $this->actingAs($this->owner)->put($url.'/order', ['names' => ['Engineer']])->assertSessionHasErrors('names');

        $this->actingAs($this->owner)->delete($url, ['name' => 'Engineer'])->assertSessionHasNoErrors();
        $this->assertNull($this->workspace->members()->whereKey($this->dana->id)->first()->pivot->discipline);
        $this->assertNotContains('Engineer', $this->workspace->fresh()->disciplines());

        $this->actingAs($this->dana)->post($url, ['name' => 'Anything'])->assertForbidden();
    }

    #[Test]
    public function workload_groups_follow_the_lists_order(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->workspace->members()->attach($sam->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now(), 'discipline' => 'Designer']);

        $this->workspace->forceFill(['settings' => ['disciplines' => ['Designer', 'Developer']]])->save();
        $this->assertSame(['Designer', 'Developer', 'No discipline set'], array_column($this->page()['groups'], 'discipline'));

        $this->workspace->forceFill(['settings' => ['disciplines' => ['Developer', 'Designer']]])->save();
        $this->assertSame(['Developer', 'Designer', 'No discipline set'], array_column($this->page()['groups'], 'discipline'));
    }

    private function book(User $as, ?User $for, string $from, string $to, ?string $note = null)
    {
        return $this->actingAs($as)->post($this->workspaceUrl($this->workspace, '/time-off'), [
            'user_id' => $for?->id, 'starts_on' => $from, 'ends_on' => $to, 'note' => $note,
        ]);
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        return $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/workload'))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function person(string $name): array
    {
        return collect($this->page()['groups'])->flatMap(fn ($g) => $g['people'])->firstWhere('name', $name);
    }

    private function issue(string $title, ?User $assignee, string $from, string $to, int $hours): void
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title, 'assignee_id' => $assignee?->id], $this->owner));
        $issue->forceFill(['start_on' => $from, 'due_on' => $to, 'estimate_minutes' => $hours * 60])->save();
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
