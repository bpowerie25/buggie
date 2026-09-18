<?php

namespace App\Http\Controllers;

use App\Enums\StatusCategory;
use App\Http\Requests\StoreStatusRequest;
use App\Models\Project;
use App\Models\Status;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Per-project workflow editing.
 *
 * Statuses are the one place where a customer's own vocabulary meets a fixed
 * invariant: names are theirs, categories are ours, and "is this issue open?" must
 * keep working whatever anybody calls their columns.
 */
class StatusController extends Controller
{
    public function store(StoreStatusRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $status = DB::transaction(function () use ($request, $project) {
            $status = $project->statuses()->create([
                ...$request->safe()->except('is_default'),
                'position' => (int) $project->statuses()->max('position') + 1,
                'is_default' => false,
            ]);

            if ($request->boolean('is_default')) {
                $this->makeDefault($project, $status);
            }

            return $status;
        });

        return back()->with('success', "“{$status->name}” added.");
    }

    public function update(StoreStatusRequest $request, Status $status): RedirectResponse
    {
        $this->authorize('update', $status->project);

        DB::transaction(function () use ($request, $status) {
            $status->update($request->safe()->except('is_default'));

            if ($request->boolean('is_default')) {
                $this->makeDefault($status->project, $status);
            }
        });

        return back()->with('success', 'Workflow updated.');
    }

    /**
     * Remove a status, moving anything in it somewhere else first.
     *
     * Deleting a status silently is how a tracker loses issues: they would either
     * cascade away or be left pointing at nothing.
     */
    public function destroy(Request $request, Status $status): RedirectResponse
    {
        $this->authorize('update', $status->project);

        $project = $status->project;

        if ($project->statuses()->count() <= 1) {
            throw ValidationException::withMessages([
                'status' => 'A project needs at least one status.',
            ]);
        }

        // Something must remain that a new issue can start in.
        if ($status->category->isOpen() && $project->statuses()->open()->count() <= 1) {
            throw ValidationException::withMessages([
                'status' => 'Keep at least one open status — new issues have to start somewhere.',
            ]);
        }

        $inUse = $status->issues()->count();

        $validated = $request->validate([
            'move_to' => [
                $inUse > 0 ? 'required' : 'nullable',
                Rule::exists('statuses', 'id')
                    ->where('project_id', $project->id)
                    ->whereNot('id', $status->id),
            ],
        ], [], ['move_to' => 'replacement status']);

        DB::transaction(function () use ($status, $project, $validated, $inUse) {
            if ($inUse > 0) {
                $status->issues()->update(['status_id' => $validated['move_to']]);
            }

            $wasDefault = $status->is_default;
            $status->delete();

            if ($wasDefault) {
                $replacement = $project->statuses()->open()->orderBy('position')->first()
                    ?? $project->statuses()->orderBy('position')->first();

                if ($replacement) {
                    $this->makeDefault($project, $replacement);
                }
            }
        });

        return back()->with('success', $inUse > 0
            ? "Status removed and {$inUse} issue".($inUse === 1 ? '' : 's').' moved.'
            : 'Status removed.');
    }

    public function reorder(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Scoped to this project, so ids from elsewhere simply are not found.
        $statuses = $project->statuses()->whereIn('id', $validated['ids'])->pluck('id');

        DB::transaction(function () use ($validated, $statuses, $project) {
            foreach ($validated['ids'] as $position => $id) {
                if ($statuses->contains($id)) {
                    $project->statuses()->whereKey($id)->update(['position' => $position]);
                }
            }
        });

        return back();
    }

    /** Exactly one default per project, and it has to be one an issue can open in. */
    private function makeDefault(Project $project, Status $status): void
    {
        if (! $status->category->isOpen()) {
            throw ValidationException::withMessages([
                'is_default' => 'New issues cannot start in a closed status.',
            ]);
        }

        $project->statuses()->update(['is_default' => false]);
        $status->forceFill(['is_default' => true])->save();
    }
}
