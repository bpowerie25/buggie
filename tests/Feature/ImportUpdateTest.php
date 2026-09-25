<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\IssueEventType;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Import;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Imports that update: a row matching an issue already here brings its filled-in
 * cells onto it, when asked to, through the same path a person editing it would use.
 */
class ImportUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $dev;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['name' => 'Website', 'key' => 'WEB', 'slug' => 'web']));

        // A developer, not an admin: importing is theirs to do too.
        $this->dev = User::factory()->create(['name' => 'Dana Dev', 'email' => 'dana@matrix.test']);
        $this->workspace->members()->attach($this->dev->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);
    }

    #[Test]
    public function a_row_keyed_with_an_issue_updates_only_what_is_filled_in(): void
    {
        $issue = $this->issue('Menu overlaps logo');

        $import = $this->import("Key,Title,Status,Assignee,Due,Estimate\nWEB-1,,Done,dana@matrix.test,31/10/2026,3\n", update: true);

        $this->assertSame([0, 1, 0], [$import->imported, $import->updated, $import->skipped]);

        $issue = $this->reload($issue);
        $this->assertSame('Menu overlaps logo', $issue->title, 'A blank Title cell left the title alone.');
        $this->assertSame(StatusCategory::Done, $issue->status->category);
        $this->assertSame($this->dev->id, $issue->assignee_id);
        $this->assertSame('2026-10-31', $issue->due_on->toDateString());
        $this->assertSame(180, $issue->estimate_minutes);

        // As if a person had made each change: it is in the activity, by whoever ran it.
        $event = $this->tenant(fn () => $issue->events()->where('type', IssueEventType::StatusChanged->value)->sole());
        $this->assertSame($this->dev->id, $event->user_id);
    }

    #[Test]
    public function without_asking_matching_rows_are_skipped_and_nothing_changes(): void
    {
        $issue = $this->issue('Menu overlaps logo');

        $import = $this->import("Key,Title\nWEB-1,Something else entirely\n", update: false);

        $this->assertSame([0, 0, 1], [$import->imported, $import->updated, $import->skipped]);
        $this->assertSame('Menu overlaps logo', $this->reload($issue)->title);
        $this->assertStringContainsString('Matches WEB-1', $import->problems[0]['message']);
    }

    #[Test]
    public function a_second_import_of_the_same_file_updates_what_the_first_created(): void
    {
        $this->import("Key,Title,Priority\nEXT-1,Checkout fails,Low\n", update: false);
        $import = $this->import("Key,Title,Priority\nEXT-1,Checkout fails on Safari,Urgent\n", update: true);

        $this->assertSame([0, 1], [$import->imported, $import->updated]);

        $issue = $this->tenant(fn () => Issue::where('source_key', 'EXT-1')->sole());
        $this->assertSame('Checkout fails on Safari', $issue->title);
        $this->assertSame(4, $issue->priority->value);
    }

    #[Test]
    public function a_parent_can_be_another_row_in_the_same_file_or_an_issue_already_here(): void
    {
        $existing = $this->issue('Redesign');

        $import = $this->import(
            "Key,Title,Parent\n"
            ."EXT-2,Header,EXT-1\n"      // Its parent comes later in the file.
            ."EXT-1,Build the site,\n"
            ."EXT-3,Footer,WEB-1\n"
            ."EXT-4,Nested,EXT-2\n",     // EXT-2 is a subtask already: refused, and said so.
            update: false,
        );

        $this->assertSame(4, $import->imported);

        $key = fn (string $source) => $this->tenant(fn () => Issue::where('source_key', $source)->sole());
        $this->assertSame($key('EXT-1')->id, $key('EXT-2')->parent_id);
        $this->assertSame($existing->id, $key('EXT-3')->parent_id);
        $this->assertNull($key('EXT-4')->parent_id);
        $this->assertStringContainsString('already a subtask', collect($import->problems)->pluck('message')->implode(' '));
    }

    #[Test]
    public function the_issue_lists_own_export_edited_and_imported_back_changes_only_what_was_edited(): void
    {
        $issue = $this->issue('Menu overlaps logo');
        $this->tenant(fn () => $issue->forceFill(['estimate_minutes' => 150, 'due_on' => '2026-11-02', 'assignee_id' => $this->dev->id])->save());

        $csv = $this->actingAs($this->dev)
            ->get($this->workspaceUrl($this->workspace, '/issues/export?q=project:web'))
            ->streamedContent();

        $import = $this->import(str_replace('Menu overlaps logo', 'Menu overlaps the logo on mobile', $csv), update: true);

        $this->assertSame([0, 1], [$import->imported, $import->updated]);

        $issue = $this->reload($issue);
        $this->assertSame('Menu overlaps the logo on mobile', $issue->title);
        $this->assertSame([150, '2026-11-02', $this->dev->id], [$issue->estimate_minutes, $issue->due_on->toDateString(), $issue->assignee_id]);

        $events = $this->tenant(fn () => $issue->events()->pluck('type')->map(fn ($t) => $t->value)->all());
        $this->assertNotContains(IssueEventType::StatusChanged->value, $events, 'An unedited column changed something.');
        $this->assertNotContains(IssueEventType::DatesChanged->value, $events);
    }

    #[Test]
    public function the_preview_says_which_rows_are_new_and_what_each_match_would_change(): void
    {
        $this->issue('Menu overlaps logo');

        $upload = $this->upload("Key,Title,Status\nWEB-1,Menu overlaps logo,Done\nNEW-1,Something new,\n");

        $preview = $this->actingAs($this->dev)
            ->get($this->workspaceUrl($this->workspace, "/projects/web/imports/{$upload->id}"))
            ->viewData('page')['props']['preview'];

        $this->assertSame(['new' => 1, 'matching' => 1, 'examples' => 0], $preview['totals']);
        $this->assertSame('WEB-1', $preview['rows'][0]['matches']);
        $this->assertSame(['status_id'], $preview['rows'][0]['changes'], 'The title is the same, so it is not a change.');
        $this->assertNull($preview['rows'][1]['matches']);
    }

    private function import(string $csv, bool $update): Import
    {
        $import = $this->upload($csv);

        $this->actingAs($this->dev)
            ->patch($this->workspaceUrl($this->workspace, "/projects/web/imports/{$import->id}"), ['update_existing' => $update])
            ->assertRedirect();

        return $import->fresh();
    }

    private function upload(string $csv): Import
    {
        $this->actingAs($this->dev)
            ->post($this->workspaceUrl($this->workspace, '/projects/web/imports'), [
                'file' => UploadedFile::fake()->createWithContent('work.csv', $csv),
            ])
            ->assertRedirect();

        return Import::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function issue(string $title): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle($this->project, ['title' => $title], $this->owner));
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::with('status')->findOrFail($issue->id));
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
