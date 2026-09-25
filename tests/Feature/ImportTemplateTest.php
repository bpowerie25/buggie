<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\WorkspaceRole;
use App\Models\Import;
use App\Models\Issue;
use App\Models\Phase;
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
 * The downloadable import template: the right columns, this project's own words, and
 * example rows that can be left in without creating anything.
 */
class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');

        // A template with its own status names, so the example rows must use them.
        $this->project = app(Tenancy::class)->run($this->workspace, fn () => app(CreateProject::class)->handle([
            'name' => 'Kennco', 'key' => 'KD', 'template' => 'client_website',
        ]));
    }

    #[Test]
    public function it_downloads_a_csv_in_this_projects_words(): void
    {
        $response = $this->actingAs($this->owner)->get($this->url('/imports/template'))->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('filename="KD-import-template.csv"', $response->headers->get('Content-Disposition'));

        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Without a BOM, Excel mangles accented names.');

        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame([
            'Key', 'Title', 'Description', 'Status', 'Priority', 'Type', 'Assignee',
            'Start', 'Due', 'Estimate', 'Phase', 'Parent', 'Created',
        ], $rows[0]);
        $this->assertCount(4, $rows);

        // "Scoped", "Building" and "Live" are this template's names, not generic ones.
        $this->assertSame(['Scoped', 'Building', 'Live'], array_column(array_slice($rows, 1), 3));
        $this->assertSame($this->owner->email, $rows[1][6]);
    }

    #[Test]
    public function any_member_of_staff_can_download_it_and_a_client_cannot(): void
    {
        // Importing is day-to-day work, not project setup: a developer or designer
        // bringing a client's spreadsheet in should not need an admin to do it.
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);
        $this->actingAs($member)->get($this->url('/imports/template'))->assertOk();
        $this->actingAs($member)->get($this->url('/import'))->assertOk();

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->actingAs($client)->get($this->url('/imports/template'))->assertForbidden();
        $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/import'))->assertNotFound();
    }

    #[Test]
    public function the_untouched_template_imports_nothing(): void
    {
        Storage::fake('local');

        $csv = $this->actingAs($this->owner)->get($this->url('/imports/template'))->getContent();
        $import = $this->upload($csv);

        $this->actingAs($this->owner)->get($this->url("/imports/{$import->id}"))
            ->assertInertia(fn ($page) => $page
                ->where('preview.rows.0.example', true)
                ->where('preview.rows.0.notes.0', 'An example row from the template; it will be skipped.'));

        $this->start($import);

        $import->refresh();
        $this->assertSame(0, $import->imported);
        $this->assertSame(3, $import->skipped);
        $this->assertSame(0, Issue::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_filled_in_template_imports_what_was_written(): void
    {
        Storage::fake('local');

        $csv = $this->actingAs($this->owner)->get($this->url('/imports/template'))->getContent()
            .'KEN-7,Menu overlaps logo on mobile,Only below 400px,Building,Urgent,Bug,'.$this->owner->email
            .",14/10/2026,2026-10-20,6,Design,,2026-08-01\n";

        $import = $this->upload($csv);
        $this->start($import);

        $this->assertSame(1, $import->fresh()->imported);

        $issue = Issue::withoutGlobalScopes()->with('status')->sole();
        $this->assertSame('Menu overlaps logo on mobile', $issue->title);
        $this->assertSame('Building', $issue->status->name);
        $this->assertSame(IssuePriority::Urgent, $issue->priority);
        $this->assertSame(IssueType::Bug, $issue->type);
        $this->assertSame($this->owner->id, $issue->assignee_id);
        $this->assertSame('2026-08-01', $issue->created_at->toDateString());
        $this->assertSame('KEN-7', $issue->source_key);
        // The planning columns, with a day-first date read as the 14th of October.
        $this->assertSame(['2026-10-14', '2026-10-20', 360], [$issue->start_on->toDateString(), $issue->due_on->toDateString(), $issue->estimate_minutes]);
        $this->assertSame('Design', Phase::withoutGlobalScopes()->find($issue->phase_id)->name);
    }

    private function upload(string $csv): Import
    {
        $this->actingAs($this->owner)
            ->post($this->url('/imports'), ['file' => UploadedFile::fake()->createWithContent('KD-import-template.csv', $csv)])
            ->assertRedirect();

        return Import::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function start(Import $import): void
    {
        $this->actingAs($this->owner)->patch($this->url("/imports/{$import->id}"))->assertRedirect();
    }

    private function url(string $path): string
    {
        return $this->workspaceUrl($this->workspace, "/projects/{$this->project->slug}{$path}");
    }
}
