<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Issue;
use App\Models\Workspace;
use App\Support\Imports\CsvFormat;
use App\Support\Imports\CsvReader;
use App\Support\Imports\RowMapper;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turn an uploaded CSV into issues.
 *
 * Queued, because twenty thousand rows is not something to do while somebody waits,
 * and one row failing must not abandon the other nineteen thousand.
 */
class RunImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $importId, public int $workspaceId) {}

    public function handle(Tenancy $tenancy): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $tenancy->run($workspace, function () {
            $import = Import::find($this->importId);

            if ($import === null || $import->state !== 'importing') {
                return;
            }

            $path = Storage::disk('local')->path($import->path);
            $reader = new CsvReader($path);
            $mapping = CsvFormat::map($reader->headers(), $import->format);
            $mapper = new RowMapper($import->project);

            $imported = 0;
            $skipped = 0;
            $problems = [];

            foreach ($reader->rows($mapping) as $number => $row) {
                try {
                    [$created, $note] = $this->import($import, $mapper, $row);

                    $created ? $imported++ : $skipped++;

                    if ($note !== null && count($problems) < 200) {
                        $problems[] = ['row' => $number, 'message' => $note];
                    }
                } catch (Throwable $e) {
                    $skipped++;

                    // Capped: an import that fails on every row should not write a
                    // twenty-thousand-entry column nobody will read.
                    if (count($problems) < 200) {
                        $problems[] = ['row' => $number, 'message' => $e->getMessage()];
                    }
                }
            }

            $import->forceFill([
                'state' => 'done',
                'imported' => $imported,
                'skipped' => $skipped,
                'problems' => $problems,
                'finished_at' => now(),
            ])->save();

            // The file has done its job and is somebody's exported bug history.
            Storage::disk('local')->delete($import->path);
        });
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: bool, 1: string|null} created, and anything worth saying
     */
    private function import(Import $import, RowMapper $mapper, array $row): array
    {
        if (($row['title'] ?? '') === '') {
            return [false, 'No title; skipped.'];
        }

        ['attributes' => $attributes, 'notes' => $notes] = $mapper->map($row);

        // Already imported: running the same file twice should change nothing rather
        // than double somebody's backlog.
        if ($attributes['source_key'] !== null) {
            $existing = Issue::where('project_id', $import->project_id)
                ->where('source_key', $attributes['source_key'])
                ->exists();

            if ($existing) {
                return [false, "Already imported as {$attributes['source_key']}."];
            }
        }

        $project = $import->project;
        $number = $project->nextIssueNumber();

        $issue = new Issue([
            'project_id' => $project->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'],
            'description_text' => \App\Support\RichText\TiptapDocument::toPlainText($attributes['description']),
            'type' => $attributes['type'],
            'status_id' => $attributes['status_id'],
            'priority' => $attributes['priority'],
            'assignee_id' => $attributes['assignee_id'],
        ]);

        $issue->forceFill([
            'number' => $number,
            'key' => "{$project->key}-{$number}",
            'source_key' => $attributes['source_key'],
            'first_seen_at' => $attributes['created_at'] ?? now(),
            'last_seen_at' => now(),
            // Preserved where the export had one: a backlog that all arrived today
            // has lost the thing that made it a history.
            'created_at' => $attributes['created_at'] ?? now(),
        ]);

        $issue->save();

        return [true, $notes === [] ? null : implode(' ', $notes)];
    }
}
