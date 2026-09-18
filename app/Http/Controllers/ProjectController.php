<?php

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Enums\StatusCategory;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Status;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('projects/index', [
            'projects' => Project::query()
                ->orderBy('is_archived')
                ->orderBy('name')
                ->get()
                ->map($this->summary(...)),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('projects/create');
    }

    public function store(StoreProjectRequest $request, CreateProject $action): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $project = $action->handle($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "Project {$project->key} created.");
    }

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => [
                ...$this->summary($project),
                'issue_sequence' => $project->issue_sequence,
                'created_at' => $project->created_at->toDateString(),
            ],
            'statuses' => $project->statuses->map(fn (Status $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category->value,
                'color' => $s->color,
                'position' => $s->position,
                'is_default' => $s->is_default,
                'open' => $s->category->isOpen(),
            ]),
            'categories' => StatusCategory::options(),
        ]);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('projects/edit', [
            'project' => $this->summary($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', "Project {$project->key} deleted.");
    }

    /** @return array<string, mixed> */
    protected function summary(Project $project): array
    {
        return [
            'name' => $project->name,
            'key' => $project->key,
            'slug' => $project->slug,
            'description' => $project->description,
            'is_archived' => $project->is_archived,
        ];
    }
}
