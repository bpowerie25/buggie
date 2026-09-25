<?php

namespace App\Http\Controllers;

use App\Jobs\RunImport;
use App\Models\Import;
use App\Models\Project;
use App\Support\Imports\CsvFormat;
use App\Support\Imports\CsvReader;
use App\Support\Imports\ImportTemplate;
use App\Support\Imports\RowMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    /**
     * The Import page: the template to fill in, the upload, and what was imported
     * before. Its own page rather than a section of project settings, because
     * bringing work in is something the team does, not something set up once.
     *
     * Reached with no project from the issue list, it asks which one.
     */
    public function index(Request $request, ?Project $project = null): Response
    {
        $projects = Project::active()->orderBy('name')->get()
            ->filter(fn (Project $p) => $request->user()->can('import', $p))
            ->values();

        abort_if($projects->isEmpty(), 404);

        if ($project !== null) {
            $this->authorize('import', $project);
        }

        return Inertia::render('imports/index', [
            'project' => $project?->only(['id', 'name', 'key', 'slug']),
            'projects' => $projects->map->only(['id', 'name', 'key', 'slug']),
            'statuses' => $project ? $project->statuses()->orderBy('position')->pluck('name') : [],
            'phases' => $project ? $project->phases()->pluck('name') : [],
            'recent' => $project
                ? Import::where('project_id', $project->id)->with('user:id,name')->latest()->limit(10)->get()
                    ->map(fn (Import $import) => [
                        'id' => $import->id,
                        'filename' => $import->filename,
                        'state' => $import->state,
                        'imported' => $import->imported,
                        'updated' => $import->updated,
                        'skipped' => $import->skipped,
                        'by' => $import->user?->name,
                        'at' => $import->created_at->toDateString(),
                    ])
                : [],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('import', $project);

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

    /**
     * A spreadsheet to fill in, written for this project: its own status names, and
     * example rows the importer will skip if they are left in.
     */
    public function template(Request $request, Project $project): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('import', $project);

        return response(ImportTemplate::csv($project, $request->user()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$project->key.'-import-template.csv"',
        ]);
    }

    /** What will happen, before it happens. */
    public function show(Request $request, Project $project, Import $import): Response
    {
        $this->authorize('import', $project);

        abort_unless($import->project_id === $project->id, 404);

        $preview = $import->state === 'previewing' ? $this->preview($import) : null;

        return Inertia::render('imports/show', [
            'project' => $project->only(['name', 'key', 'slug']),
            'import' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'state' => $import->state,
                'imported' => $import->imported,
                'updated' => $import->updated,
                'skipped' => $import->skipped,
                'update_existing' => $import->update_existing,
                'problems' => $import->problems,
                'finished_at' => $import->finished_at?->toIso8601String(),
            ],
            'preview' => $preview,
        ]);
    }

    public function update(Request $request, Project $project, Import $import): RedirectResponse
    {
        $this->authorize('import', $project);

        abort_unless($import->project_id === $project->id && $import->state === 'previewing', 404);

        // Detected on the way in rather than trusted from the form, so a tampered
        // field cannot make Jira columns be read as Mantis ones.
        $reader = new CsvReader(Storage::disk('local')->path($import->path));

        $import->forceFill([
            'format' => CsvFormat::detect($reader->headers()),
            'state' => 'importing',
            'total_rows' => $reader->count(),
            // Chosen on the preview: bring the file's changes onto the issues it
            // matches, or leave them alone. Off unless asked for.
            'update_existing' => $request->boolean('update_existing'),
        ])->save();

        RunImport::dispatch($import->id, $import->workspace_id);

        return back()->with('success', 'Import started. It will finish in the background.');
    }

    public function destroy(Request $request, Project $project, Import $import): RedirectResponse
    {
        $this->authorize('import', $project);

        abort_unless($import->project_id === $project->id, 404);

        Storage::disk('local')->delete($import->path);
        $import->delete();

        return redirect()->route('imports.index', $project);
    }

    /**
     * The first few rows, as they would be created.
     *
     * @return array<string, mixed>|null
     */
    private function preview(Import $import): ?array
    {
        try {
            $reader = new CsvReader(Storage::disk('local')->path($import->path));
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

            $mapper = new RowMapper($import->project);
            $rows = [];
            // Across every row, so the buttons can say how many of each there are.
            $totals = ['new' => 0, 'matching' => 0, 'examples' => 0];

            foreach ($reader->rows($mapping) as $row) {
                $key = ($row['source_key'] ?? '') === '' ? null : trim($row['source_key']);

                if (ImportTemplate::isExample($key)) {
                    $totals['examples']++;
                    $existing = null;
                } else {
                    $totals[$mapper->matchId($key) !== null ? 'matching' : 'new']++;
                }

                if (count($rows) >= 10) {
                    continue;
                }

                $existing = ImportTemplate::isExample($key) ? null : $mapper->existing($key);

                ['attributes' => $attributes, 'notes' => $notes] = $mapper->map($row);
                $example = ImportTemplate::isExample($attributes['source_key']);

                $rows[] = [
                    'source_key' => $attributes['source_key'],
                    // Shown, so it is plain they were seen and will be left out.
                    'example' => $example,
                    'title' => $attributes['title'] !== '' ? $attributes['title'] : $existing?->title,
                    'status' => $import->project->statuses->firstWhere('id', $attributes['status_id'])?->name,
                    'type' => $attributes['type'],
                    'assignee' => $attributes['assignee_id'] !== null,
                    // The issue it would update, and what would change on it.
                    'matches' => $existing?->key,
                    'changes' => $existing ? array_keys($mapper->changes($existing, $row)) : [],
                    'notes' => $example ? ['An example row from the template; it will be skipped.'] : $notes,
                ];
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
                'totals' => $totals,
            ];
        } catch (Throwable $e) {
            return ['error' => 'That file could not be read: '.$e->getMessage()];
        }
    }
}
