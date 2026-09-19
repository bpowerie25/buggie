<?php

namespace App\Http\Controllers;

use App\Jobs\RunImport;
use App\Models\Import;
use App\Models\Project;
use App\Support\Imports\CsvFormat;
use App\Support\Imports\CsvReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Bringing a backlog over from another tracker.
 *
 * Nobody moves tracker without their history, so this is less a feature than the
 * thing that makes moving possible at all.
 */
class ImportController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            // mimes rather than a MIME type: browsers disagree about what a CSV is,
            // and a file from Excel arrives as half a dozen different types.
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
        ]);

        $path = $request->file('file')->store('imports', 'local');

        $import = Import::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'filename' => $request->file('file')->getClientOriginalName(),
            'path' => $path,
            'format' => CsvFormat::GENERIC,
            'state' => 'previewing',
        ]);

        return redirect()->route('imports.show', [$project, $import]);
    }

    /** What will happen, before it happens. */
    public function show(Request $request, Project $project, Import $import): Response
    {
        $this->authorize('update', $project);

        abort_unless($import->project_id === $project->id, 404);

        $preview = $import->state === 'previewing' ? $this->preview($import) : null;

        return Inertia::render('imports/show', [
            'project' => $project->only(['name', 'key', 'slug']),
            'import' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'state' => $import->state,
                'imported' => $import->imported,
                'skipped' => $import->skipped,
                'problems' => $import->problems,
                'finished_at' => $import->finished_at?->toIso8601String(),
            ],
            'preview' => $preview,
        ]);
    }

    public function update(Request $request, Project $project, Import $import): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($import->project_id === $project->id && $import->state === 'previewing', 404);

        // Detected on the way in rather than trusted from the form, so a tampered
        // field cannot make Jira columns be read as Mantis ones.
        $reader = new CsvReader(\Illuminate\Support\Facades\Storage::disk('local')->path($import->path));

        $import->forceFill([
            'format' => CsvFormat::detect($reader->headers()),
            'state' => 'importing',
            'total_rows' => $reader->count(),
        ])->save();

        RunImport::dispatch($import->id, $import->workspace_id);

        return back()->with('success', 'Import started. It will finish in the background.');
    }

    public function destroy(Request $request, Project $project, Import $import): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($import->project_id === $project->id, 404);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($import->path);
        $import->delete();

        return redirect()->route('projects.edit', $project);
    }

    /**
     * The first few rows, as they would be created.
     *
     * @return array<string, mixed>|null
     */
    private function preview(Import $import): ?array
    {
        try {
            $reader = new CsvReader(\Illuminate\Support\Facades\Storage::disk('local')->path($import->path));
            $headers = $reader->headers();

            if ($headers === []) {
                return ['error' => 'That file has no columns in it.'];
            }

            $format = CsvFormat::detect($headers);
            $mapping = CsvFormat::map($headers, $format);

            if (! isset($mapping['title'])) {
                return [
                    'error' => 'No column looks like a title. Expected one called '
                        .'Summary, Title or Subject.',
                    'headers' => $headers,
                ];
            }

            $mapper = new \App\Support\Imports\RowMapper($import->project);
            $rows = [];

            foreach ($reader->rows($mapping) as $row) {
                ['attributes' => $attributes, 'notes' => $notes] = $mapper->map($row);

                $rows[] = [
                    'source_key' => $attributes['source_key'],
                    'title' => $attributes['title'],
                    'status' => $import->project->statuses->firstWhere('id', $attributes['status_id'])?->name,
                    'type' => $attributes['type'],
                    'assignee' => $attributes['assignee_id'] !== null,
                    'notes' => $notes,
                ];

                if (count($rows) >= 10) {
                    break;
                }
            }

            return [
                'format' => CsvFormat::label($format),
                'total' => $reader->count(),
                'capped' => $reader->count() > CsvReader::MAX_ROWS,
                'max' => CsvReader::MAX_ROWS,
                'mapped' => array_keys($mapping),
                'ignored' => array_values(array_diff(
                    array_map(fn ($h) => trim($h), $headers),
                    array_map(fn ($i) => trim($headers[$i]), $mapping),
                )),
                'rows' => $rows,
            ];
        } catch (Throwable $e) {
            return ['error' => 'That file could not be read: '.$e->getMessage()];
        }
    }
}
