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
            'clientWait' => [
                'reminder_days' => $project->clientWaitDays('awaiting_reminder_days'),
                'close_days' => $project->clientWaitDays('awaiting_close_days'),
                'has_status' => $project->awaitingClientStatus() !== null,
            ],
            'clientTimeline' => $project->showsTimelineToClients(),
            'statuses' => $project->statuses()->withCount('issues')->get()
                ->map(fn (Status $status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'color' => $status->color,
                    'position' => $status->position,
                    'is_default' => $status->is_default,
                    'is_awaiting_client' => $status->is_awaiting_client,
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
                // When, never what: the secret itself is shown once, on creation or
                // rotation, and is otherwise never sent to a browser.
                'secret_rotated_at' => $key->secret_rotated_at?->toIso8601String(),
            ]),
            'widgetModes' => array_map(
                fn (\App\Enums\WidgetMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                \App\Enums\WidgetMode::cases(),
            ),
            // Only on the page load straight after creating or rotating, for the person
            // who did it. See WidgetKeyController::reveal().
            'revealedSecret' => session('widget_secret'),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validated();
        $wait = array_intersect_key($validated, array_flip(['awaiting_reminder_days', 'awaiting_close_days', 'client_timeline']));

        if (array_key_exists('client_timeline', $wait)) {
            $wait['client_timeline'] = (bool) $wait['client_timeline'];
        }

        $project->update(array_diff_key($validated, $wait));

        if ($wait !== []) {
            $project->forceFill(['settings' => [...($project->settings ?? []), ...$wait]])->save();
        }

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
