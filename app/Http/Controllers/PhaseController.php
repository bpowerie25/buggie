<?php

namespace App\Http\Controllers;

use App\Models\Phase;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A project's phases, managed from its settings.
 *
 * The same shape as versions: named per project, unique within it, and deleting one
 * keeps its issues. Order matters here where it does not for a release — phases are
 * drawn on the timeline in the order the job runs.
 */
class PhaseController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('phases', 'name')->where('project_id', $project->id),
            ],
        ]);

        // At the end: a new phase is almost always the next stage of the job.
        $project->phases()->create([
            'name' => $validated['name'],
            'position' => (int) Phase::where('project_id', $project->id)->max('position') + 1,
        ]);

        return back()->with('success', "Phase {$validated['name']} added.");
    }

    public function update(Request $request, Project $project, Phase $phase): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($phase->project_id === $project->id, 404);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('phases', 'name')->where('project_id', $project->id)->ignore($phase->id),
            ],
        ]);

        $phase->update($validated);

        return back();
    }

    /**
     * The whole order at once, rather than "move this one up".
     *
     * Every id must be one of this project's phases and every phase must be named,
     * so a stale page cannot drop a phase out of the order or pull in somebody
     * else's.
     */
    public function reorder(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $ids = Phase::where('project_id', $project->id)->pluck('id')->sort()->values()->all();

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $given = collect($validated['ids'])->map(fn ($id) => (int) $id);

        if ($given->sort()->values()->all() !== $ids) {
            return back()->withErrors(['ids' => 'The phases changed while you were ordering them. Reload and try again.']);
        }

        DB::transaction(function () use ($given) {
            foreach ($given as $position => $id) {
                Phase::whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    public function destroy(Request $request, Project $project, Phase $phase): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($phase->project_id === $project->id, 404);

        // The issues stay; they stop belonging to a phase.
        $phase->issues()->update(['phase_id' => null]);
        $phase->delete();

        return back()->with('success', "Phase {$phase->name} deleted. Its issues were kept.");
    }
}
