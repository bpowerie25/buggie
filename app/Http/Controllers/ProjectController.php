<?php

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Enums\StatusCategory;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Status;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('projects/index', [
            'projects' => Project::query()
                ->visibleTo($request->user())
                ->orderBy('is_archived')
                ->orderBy('name')
                ->get()
                ->map($this->summary(...)),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('projects/create', [
            // Resolved rather than injected: controller arguments here are spliced in
            // positionally after the workspace binding is dropped, and this method
            // takes none.
            'templates' => app(\App\Support\Templates\ProjectTemplates::class)->summaries(),

            // "Make it like Acme's" is the case an agency actually has. Archived
            // projects are left out: copying the workflow of something nobody works
            // on any more is rarely what was meant, and the list is long enough.
            'sources' => Project::active()->orderBy('name')->get()->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'key' => $p->key,
            ]),

            // Which one is preselected, so the form does not have to guess from the
            // order the config file happens to be written in.
            'defaultTemplate' => (string) config('templates.default'),
        ]);
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
                'wip_limit' => $s->wip_limit,
            ]),
            'categories' => StatusCategory::options(),

            // Unreleased first: what people are working towards matters more than
            // what already shipped.
            'versions' => $project->versions()->inWorkingOrder()->withCount('issues')->get()
                ->map(fn (\App\Models\Version $version) => [
                    'id' => $version->id,
                    'name' => $version->name,
                    'description' => $version->description,
                    'released_at' => $version->released_at?->toDateString(),
                    'issues_count' => $version->issues_count,
                ]),
        ]);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('projects/edit', [
            'project' => $this->summary($project),
            'statuses' => $project->statuses()->withCount('issues')->get()
                ->map(fn (Status $status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'color' => $status->color,
                    'position' => $status->position,
                    'is_default' => $status->is_default,
                    'open' => $status->category->isOpen(),
                    'issues_count' => $status->issues_count,
                    'wip_limit' => $status->wip_limit,
                ]),
            'categories' => StatusCategory::options(),

            // Unreleased first: what people are working towards matters more than
            // what already shipped.
            'versions' => $project->versions()->inWorkingOrder()->withCount('issues')->get()
                ->map(fn (\App\Models\Version $version) => [
                    'id' => $version->id,
                    'name' => $version->name,
                    'description' => $version->description,
                    'released_at' => $version->released_at?->toDateString(),
                    'issues_count' => $version->issues_count,
                ]),
            'customFields' => \App\Models\CustomField::where('project_id', $project->id)
                ->inOrder()->get()
                ->map(fn (\App\Models\CustomField $field) => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'key' => $field->key,
                    'type' => $field->type->value,
                    'options' => $field->options ?? [],
                    'required' => $field->required,
                    'visible_to_client' => $field->visible_to_client,
                ]),
            'fieldTypes' => array_map(
                fn (\App\Enums\CustomFieldType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'has_options' => $type->hasOptions(),
                ],
                \App\Enums\CustomFieldType::cases(),
            ),
            // Generated since M5 and never once displayed, which made filing by email
            // impossible without database access.
            'inboundAddress' => $project->inboundAddress(),
            // Whether that address is a destination or a placeholder. Offering one to
            // copy without saying which is how somebody emails into silence.
            'inboundReason' => app(\App\Support\Mail\InboundMail::class)->reason(),
            'branding' => [
                'name' => $project->brand_name,
                'color' => $project->brand_color,
                'logo' => $project->branding()['logo'],
                'placeholder' => $project->name,
            ],
            'widgetKeys' => $project->widgetKeys()->latest()->get()->map(fn ($key) => [
                'id' => $key->id,
                'public_key' => $key->public_key,
                'allowed_origins' => $key->allowed_origins,
                'mode' => $key->mode,
                'require_email' => $key->require_email,
                'capture_screenshot' => $key->capture_screenshot,
                'is_active' => $key->is_active,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'snippet' => '<script src="'.central_url('w/'.$key->public_key.'.js').'" async></script>',
            ]),
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
            'site_url' => $project->site_url,
            'is_archived' => $project->is_archived,
        ];
    }
}
