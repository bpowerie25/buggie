<?php

namespace Tests\Feature;

use App\Jobs\RunImport;
use App\Models\Import;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Imports\CsvFormat;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    /** Real Jira export headers, in the order Jira writes them. */
    private const JIRA = "Issue key,Issue Type,Status,Priority,Summary,Description,Assignee,Reporter,Created,Labels\n";

    /** Real Mantis export headers. */
    private const MANTIS = "Id,Project,Reporter,Assigned To,Priority,Severity,Status,Summary,Description,Category,Date Submitted\n";

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Project} */
    private function project(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        return [$workspace, $staff, $project];
    }

    private function upload(string $csv, string $name = 'export.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    // ------------------------------------------------------------------ format

    #[Test]
    public function a_jira_export_is_recognised(): void
    {
        $headers = str_getcsv(trim(self::JIRA));

        $this->assertSame(CsvFormat::JIRA, CsvFormat::detect($headers));

        $mapping = CsvFormat::map($headers, CsvFormat::JIRA);

        $this->assertSame(0, $mapping['source_key']);
        $this->assertSame(4, $mapping['title']);
    }

    #[Test]
    public function a_mantis_export_is_recognised(): void
    {
        $headers = str_getcsv(trim(self::MANTIS));

        $this->assertSame(CsvFormat::MANTIS, CsvFormat::detect($headers));

        $mapping = CsvFormat::map($headers, CsvFormat::MANTIS);

        $this->assertSame(7, $mapping['title']);
        $this->assertSame(3, $mapping['assignee']);
    }

    #[Test]
    public function a_spreadsheet_somebody_typed_by_hand_still_works(): void
    {
        // The common case for a small agency: bugs have been in Google Sheets.
        $headers = ['Title', 'Notes', 'State', 'Owner'];

        $this->assertSame(CsvFormat::GENERIC, CsvFormat::detect($headers));

        $mapping = CsvFormat::map($headers, CsvFormat::GENERIC);

        $this->assertSame(0, $mapping['title']);
        $this->assertSame(1, $mapping['description']);
    }

    #[Test]
    public function a_byte_order_mark_does_not_hide_the_first_column(): void
    {
        // Excel writes one and fgetcsv does not strip it, so "Issue key" arrives as
        // "\u{FEFF}Issue key" and matches nothing.
        $headers = ["\u{FEFF}Issue key", 'Summary'];

        $this->assertSame(CsvFormat::JIRA, CsvFormat::detect($headers));
    }

    // ------------------------------------------------------------------ preview

    #[Test]
    public function the_preview_says_what_will_happen_before_it_happens(): void
    {
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $csv = self::JIRA."WEB-1,Bug,Done,High,Checkout fails,It just does,,,2024-03-01,\n";

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports"), [
                'file' => $this->upload($csv),
            ])
            ->assertRedirect();

        $import = Import::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports/{$import->id}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.format', 'Jira')
                ->where('preview.total', 1)
                ->where('preview.rows.0.title', 'Checkout fails'));

        // Nothing created yet: a preview that imports is not a preview.
        $this->assertSame(0, Issue::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_file_with_no_title_column_is_refused_with_a_reason(): void
    {
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports"), [
                'file' => $this->upload("Colour,Shape\nred,round\n"),
            ]);

        $import = Import::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports/{$import->id}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'preview.error',
                'No column looks like a title. Expected one called Summary, Title or Subject.',
            ));
    }

    // ------------------------------------------------------------------ running

    private function runImport(string $csv, ?callable $before = null): Import
    {
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $before && $before($workspace, $project);

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports"), [
                'file' => $this->upload($csv),
            ]);

        $import = Import::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($staff)
            ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports/{$import->id}"))
            ->assertRedirect();

        (new RunImport($import->id, $workspace->id))->handle(app(Tenancy::class));

        return $import->fresh();
    }

    #[Test]
    public function issues_are_created_with_their_original_dates(): void
    {
        // A backlog that all arrived today has lost the thing that made it a history.
        $import = $this->runImport(
            self::JIRA."WEB-1,Bug,To Do,High,Checkout fails on Safari,Details here,,,2021-06-04,\n"
        );

        $this->assertSame(1, $import->imported);

        $issue = Issue::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('Checkout fails on Safari', $issue->title);
        $this->assertSame('WEB-1', $issue->source_key);
        $this->assertSame('2021-06-04', $issue->created_at->toDateString());
    }

    #[Test]
    public function a_resolved_bug_does_not_arrive_as_todo(): void
    {
        // Importing a decade of fixed bugs as open work is the single most annoying
        // thing an import can do.
        $import = $this->runImport(
            self::JIRA."WEB-1,Bug,Resolved,High,Fixed years ago,,,,2020-01-01,\n"
        );

        $this->assertSame(1, $import->imported);

        $issue = Issue::withoutGlobalScopes()->with('status')->firstOrFail();

        $this->assertFalse($issue->status->category->isOpen());
    }

    #[Test]
    public function running_the_same_file_twice_changes_nothing(): void
    {
        // Somebody will, because the first one looked like it half worked.
        $csv = self::JIRA."WEB-1,Bug,To Do,High,Only once,,,,2024-01-01,\n";

        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        foreach ([1, 2] as $attempt) {
            $this->actingAs($staff)
                ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports"), [
                    'file' => $this->upload($csv),
                ]);

            $import = Import::withoutGlobalScopes()->latest('id')->firstOrFail();

            $this->actingAs($staff)
                ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports/{$import->id}"));

            (new RunImport($import->id, $workspace->id))->handle(app(Tenancy::class));
        }

        $this->assertSame(1, Issue::withoutGlobalScopes()->count());
        $this->assertSame(1, Import::withoutGlobalScopes()->latest('id')->first()->skipped);
    }

    #[Test]
    public function an_assignee_is_matched_on_email_and_said_so_when_not(): void
    {
        $import = $this->runImport(
            self::JIRA
            ."WEB-1,Bug,To Do,High,Theirs,,dev@example.com,,2024-01-01,\n"
            ."WEB-2,Bug,To Do,High,Nobody's,,someone@elsewhere.test,,2024-01-01,\n",
            function ($workspace, $project) {
                $member = User::factory()->create(['email' => 'dev@example.com']);

                $workspace->members()->attach($member->id, [
                    'role' => \App\Enums\WorkspaceRole::Member->value,
                    'joined_at' => now(),
                ]);
            },
        );

        $this->assertSame(2, $import->imported);

        $matched = Issue::withoutGlobalScopes()->where('source_key', 'WEB-1')->firstOrFail();
        $unmatched = Issue::withoutGlobalScopes()->where('source_key', 'WEB-2')->firstOrFail();

        $this->assertNotNull($matched->assignee_id);
        $this->assertNull($unmatched->assignee_id);

        // Named rather than swallowed: "who was this assigned to?" is the first
        // question after an import.
        $this->assertStringContainsString('someone@elsewhere.test', json_encode($import->problems));
    }

    #[Test]
    public function a_row_with_no_title_is_skipped_rather_than_failing_the_import(): void
    {
        // One bad row must not abandon the other nineteen thousand.
        $import = $this->runImport(
            self::JIRA
            ."WEB-1,Bug,To Do,High,Fine,,,,2024-01-01,\n"
            ."WEB-2,Bug,To Do,High,,,,,2024-01-01,\n"
            ."WEB-3,Bug,To Do,High,Also fine,,,,2024-01-01,\n"
        );

        $this->assertSame(2, $import->imported);
        $this->assertSame(1, $import->skipped);
    }

    #[Test]
    public function a_mantis_export_imports_too(): void
    {
        $import = $this->runImport(
            self::MANTIS."4821,Website,jo@acme.test,,urgent,major,closed,Login is broken,Cannot sign in,General,2019-11-02\n"
        );

        $this->assertSame(1, $import->imported);

        $issue = Issue::withoutGlobalScopes()->with('status')->firstOrFail();

        $this->assertSame('Login is broken', $issue->title);
        $this->assertSame('4821', $issue->source_key);
        $this->assertSame(\App\Enums\IssuePriority::Urgent->value, $issue->priority->value);
        $this->assertFalse($issue->status->category->isOpen());
    }

    #[Test]
    public function the_uploaded_file_is_deleted_afterwards(): void
    {
        // It is somebody's exported bug history and has done its job.
        $import = $this->runImport(self::JIRA."WEB-1,Bug,To Do,High,Done with,,,,2024-01-01,\n");

        Storage::disk('local')->assertMissing($import->path);
    }

    #[Test]
    public function a_client_cannot_import(): void
    {
        Storage::fake('local');

        [$workspace, , $project] = $this->project();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => \App\Enums\WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/imports"), [
                'file' => $this->upload(self::JIRA),
            ])
            ->assertForbidden();
    }
}
