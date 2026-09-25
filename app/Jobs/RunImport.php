<?php

namespace App\Jobs;

use App\Actions\SetParent;
use App\Actions\UpdateIssue;
use App\Models\Import;
use App\Models\Issue;
use App\Models\Workspace;
use App\Support\Imports\CsvFormat;
use App\Support\Imports\CsvReader;
use App\Support\Imports\ImportTemplate;
use App\Support\Imports\RowMapper;
use App\Support\RichText\TiptapDocument;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turn an uploaded CSV into issues, or bring its changes onto the ones it matches.
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

            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $problems = [];
            // [issue id, the parent the row named, row number]: linked once every row
            // is in, so a row may name a parent further down the same file.
            $parents = [];
            $note = function (int $row, string $message) use (&$problems) {
                // Capped: an import that fails on every row should not write a
                // twenty-thousand-entry column nobody will read.
                if (count($problems) < 200) {
                    $problems[] = ['row' => $row, 'message' => $message];
                }
            };

            foreach ($reader->rows($mapping) as $number => $row) {
                try {
                    [$outcome, $message, $issue, $parent] = $this->import($import, $mapper, $row);

                    $counts[$outcome]++;

                    if ($message !== null) {
                        $note($number, $message);
                    }

                    if ($issue !== null && $parent !== null) {
                        $parents[] = [$issue->id, $parent, $number];
                    }
                } catch (Throwable $e) {
                    $counts['skipped']++;
                    $note($number, $e->getMessage());
                }
            }

            $mapper->refresh();

            foreach ($parents as [$id, $key, $number]) {
                $parent = $mapper->existing($key);

                if ($parent === null) {
                    $note($number, "No issue {$key} to make this part of; left where it was.");

                    continue;
                }

                try {
                    app(SetParent::class)->handle(Issue::findOrFail($id), $parent);
                } catch (ValidationException $e) {
                    $note($number, collect($e->errors())->flatten()->first());
                }
            }

            $import->forceFill([
                'state' => 'done',
                'imported' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'problems' => $problems,
                'finished_at' => now(),
            ])->save();

            // The file has done its job and is somebody's exported bug history.
            Storage::disk('local')->delete($import->path);
        });
    }

    /**
     * One row: a new issue, an update to the one it matches, or nothing.
     *
     * @param  array<string, string>  $row
     * @return array{0: 'created'|'updated'|'skipped', 1: string|null, 2: Issue|null, 3: string|null}
     *                                                                                                what happened, anything worth saying, the issue, and the parent it named
     */
    private function import(Import $import, RowMapper $mapper, array $row): array
    {
        ['attributes' => $attributes, 'notes' => $notes] = $mapper->map($row);
        $said = $notes === [] ? null : implode(' ', $notes);

        // Left in from the downloadable template. Skipped rather than imported, so an
        // untouched template creates nothing and a half-edited one creates only what
        // somebody actually wrote.
        if (ImportTemplate::isExample($attributes['source_key'])) {
            return ['skipped', "{$attributes['source_key']} is an example row from the template; skipped.", null, null];
        }

        $existing = $mapper->existing($attributes['source_key']);

        if ($existing !== null) {
            // Importing the same file twice changes nothing unless updating was asked
            // for: doubling somebody's backlog is the worse mistake.
            if (! $import->update_existing) {
                return ['skipped', "Matches {$existing->key}, which is already here; skipped.", null, null];
            }

            return $this->update($import, $mapper, $existing, $row, $said);
        }

        if (($row['title'] ?? '') === '') {
            return ['skipped', 'No title; skipped.', null, null];
        }

        $project = $import->project;
        $number = $project->nextIssueNumber();

        $issue = new Issue([
            'project_id' => $project->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'],
            'description_text' => TiptapDocument::toPlainText($attributes['description']),
            'type' => $attributes['type'],
            'status_id' => $attributes['status_id'],
            'priority' => $attributes['priority'],
            'assignee_id' => $attributes['assignee_id'],
            'start_on' => $attributes['start_on'],
            'due_on' => $attributes['due_on'],
            'phase_id' => $attributes['phase'] === null ? null : $mapper->phaseFor($attributes['phase']),
        ]);

        $issue->forceFill([
            'number' => $number,
            'key' => "{$project->key}-{$number}",
            'source_key' => $attributes['source_key'],
            'estimate_minutes' => $attributes['estimate_minutes'],
            'first_seen_at' => $attributes['created_at'] ?? now(),
            'last_seen_at' => now(),
            // Preserved where the export had one: a backlog that all arrived today
            // has lost the thing that made it a history.
            'created_at' => $attributes['created_at'] ?? now(),
        ]);

        $issue->save();

        return ['created', $said, $issue, $attributes['parent']];
    }

    /**
     * Bring a row's filled-in cells onto the issue it matches.
     *
     * Through UpdateIssue, so every change appears in the issue's activity and
     * notifies whoever it would have notified had somebody made it by hand — an
     * import is somebody making those changes, many at once.
     *
     * @param  array<string, string>  $row
     * @return array{0: 'updated'|'skipped', 1: string|null, 2: Issue|null, 3: string|null}
     */
    private function update(Import $import, RowMapper $mapper, Issue $issue, array $row, ?string $said): array
    {
        $changes = $mapper->changes($issue, $row);
        $parent = $changes['parent'] ?? null;
        unset($changes['parent']);

        if ($changes === []) {
            return ['skipped', $parent === null ? null : $said, $parent === null ? null : $issue, $parent];
        }

        if (isset($changes['phase'])) {
            $changes['phase_id'] = $mapper->phaseFor($changes['phase']);
            unset($changes['phase']);
        }

        if (array_key_exists('estimate_minutes', $changes)) {
            $issue->forceFill(['estimate_minutes' => $changes['estimate_minutes']])->save();
            unset($changes['estimate_minutes']);
        }

        if ($changes !== []) {
            app(UpdateIssue::class)->handle($issue, $changes, $import->user);
        }

        return ['updated', $said, $issue, $parent];
    }
}
