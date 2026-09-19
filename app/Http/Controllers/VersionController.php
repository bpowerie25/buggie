<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Version;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Releases, and what went into them.
 *
 * The changelog is the point: something a client can be handed that says what
 * actually changed, built from the work rather than written separately and drifting
 * from it.
 */
class VersionController extends Controller
{
    public function show(Request $request, Project $project, Version $version): Response
    {
        $this->authorize('view', $project);

        abort_unless($version->project_id === $project->id, 404);

        $staff = $request->user()->membershipIn(
            app(\App\Support\Tenancy\Tenancy::class)->currentOrFail()
        )?->isStaff() ?? false;

        return Inertia::render('versions/show', [
            'project' => $project->only(['name', 'key', 'slug']),
            'version' => [
                'id' => $version->id,
                'name' => $version->name,
                'description' => $version->description,
                'released_at' => $version->released_at?->toDateString(),
            ],
            // The same visibility rules as everywhere else: a client reading a
            // changelog sees the issues they could already open, and no others.
            'issues' => $version->issues()
                ->unless($staff, fn ($q) => $q->visibleToClient($request->user()))
                ->with(['status:id,name,category', 'labels:id,name'])
                ->orderByRaw('priority DESC, key')
                ->get()
                ->map(fn ($issue) => [
                    'key' => $issue->key,
                    'title' => $issue->title,
                    'type' => $issue->type->value,
                    'status' => [
                        'name' => $issue->status->name,
                        'open' => $issue->status->category->isOpen(),
                    ],
                    'labels' => $issue->labels->pluck('name'),
                ]),
            'can_manage' => $request->user()->can('update', $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                // Two "2.4.1"s in one project is a mistake every time.
                Rule::unique('versions', 'name')->where('project_id', $project->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project->versions()->create($validated);

        return back()->with('success', "Version {$validated['name']} created.");
    }

    public function update(Request $request, Project $project, Version $version): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($version->project_id === $project->id, 404);

        $validated = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:60',
                Rule::unique('versions', 'name')
                    ->where('project_id', $project->id)
                    ->ignore($version->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'released' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('released', $validated)) {
            // Releasing stamps the date; un-releasing clears it. Both are ordinary
            // things to do — a release gets pulled, and a date typed by hand is a
            // date somebody gets wrong.
            $validated['released_at'] = $validated['released'] ? now() : null;
            unset($validated['released']);
        }

        $version->update($validated);

        return back();
    }

    public function destroy(Request $request, Project $project, Version $version): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($version->project_id === $project->id, 404);

        // The issues survive; they simply stop belonging to a release. Deleting a
        // version must never delete the work that was in it.
        $version->issues()->update(['version_id' => null]);
        $version->delete();

        return back()->with('success', "Version {$version->name} deleted. Its issues were kept.");
    }
}
