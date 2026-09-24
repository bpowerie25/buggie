<?php

namespace Tests\Feature;

use App\Actions\CreateProject;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\Workspace;
use App\Support\Templates\InvalidTemplate;
use App\Support\Templates\ProjectTemplates;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Project templates, and copying another project's setup.
 *
 * Most of this file is about what is *not* carried across, so every negative
 * assertion here is paired with a positive control. "The copy has no issues" passes
 * just as happily when the copy has nothing at all.
 */
class ProjectTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Workspace, 1: User} */
    private function acme(string $slug = 'acme'): array
    {
        return $this->workspaceWithMember(WorkspaceRole::Owner, $slug);
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(Workspace $workspace, array $attributes): Project
    {
        return app(Tenancy::class)->run(
            $workspace,
            fn () => app(CreateProject::class)->handle($attributes),
        );
    }

    /** @return \Illuminate\Support\Collection<int, Status> */
    private function statusesOf(Project $project)
    {
        return Status::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, CustomField> */
    private function fieldsOf(Project $project)
    {
        return CustomField::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get();
    }

    // ----------------------------------------------------------- built-in templates

    #[Test]
    public function every_built_in_template_creates_the_statuses_labels_and_fields_it_claims(): void
    {
        [$workspace] = $this->acme();

        $templates = app(Tenancy::class)->run($workspace, fn () => app(ProjectTemplates::class)->all());

        $this->assertGreaterThanOrEqual(3, count($templates), 'There should be something to choose between.');

        foreach ($templates as $key => $template) {
            $project = $this->create($workspace, ['name' => "Project {$key}", 'template' => $key]);

            $statuses = $this->statusesOf($project);

            $this->assertSame(
                array_column($template->statuses, 'name'),
                $statuses->pluck('name')->all(),
                "[{$key}] should create its statuses, in order.",
            );

            foreach ($template->statuses as $position => $expected) {
                $status = $statuses[$position];

                $this->assertSame($expected['category'], $status->category, "[{$key}] {$expected['name']} category");
                $this->assertSame($expected['color'], $status->color, "[{$key}] {$expected['name']} colour");
                $this->assertSame($expected['is_default'], $status->is_default, "[{$key}] {$expected['name']} default");
                $this->assertSame($expected['wip_limit'], $status->wip_limit, "[{$key}] {$expected['name']} wip limit");
                $this->assertSame($position, $status->position, "[{$key}] {$expected['name']} position");
            }

            // Every template has to leave a workflow somebody can actually work in.
            $this->assertTrue(
                $statuses->contains(fn (Status $s) => $s->category->isOpen()),
                "[{$key}] has no open status.",
            );
            $this->assertTrue(
                $statuses->contains(fn (Status $s) => $s->category === StatusCategory::Done),
                "[{$key}] has nothing in the done category.",
            );
            $this->assertCount(1, $statuses->where('is_default', true), "[{$key}] needs exactly one default.");

            $fields = $this->fieldsOf($project);

            $this->assertSame(
                array_column($template->fields, 'name'),
                $fields->pluck('name')->all(),
                "[{$key}] should create its fields, in order.",
            );

            foreach ($template->fields as $position => $expected) {
                $field = $fields[$position];

                $this->assertSame($expected['type'], $field->type, "[{$key}] {$expected['name']} type");
                $this->assertSame($expected['options'], $field->options, "[{$key}] {$expected['name']} options");
                $this->assertSame($expected['required'], $field->required, "[{$key}] {$expected['name']} required");

                // Internal unless somebody decides otherwise, on the project.
                $this->assertFalse(
                    $field->visible_to_client,
                    "[{$key}] {$expected['name']} must not arrive client-visible.",
                );
            }

            $labels = Label::withoutGlobalScopes()->where('workspace_id', $workspace->id)->pluck('name')->all();

            foreach ($template->labels as $label) {
                $this->assertContains(
                    $label['name'],
                    $labels,
                    "[{$key}] should have created the label {$label['name']}.",
                );
            }
        }
    }

    #[Test]
    public function only_the_fallback_gives_todays_defaults(): void
    {
        [$workspace] = $this->acme();

        $defaults = array_column(Status::DEFAULTS, 'name');
        $fallbackKey = config('templates.default');

        $templates = app(Tenancy::class)->run($workspace, fn () => app(ProjectTemplates::class)->all());

        foreach ($templates as $key => $template) {
            $names = array_column($template->statuses, 'name');

            if ($key === $fallbackKey) {
                // The positive control: the fallback is today's workflow, unchanged.
                $this->assertSame($defaults, $names, 'The fallback must stay what every project has always had.');

                continue;
            }

            $this->assertNotSame(
                $defaults,
                $names,
                "[{$key}] is the default workflow under another name, so it is not a choice.",
            );
        }
    }

    #[Test]
    public function a_project_created_without_a_template_gets_the_defaults(): void
    {
        [$workspace, $user] = $this->acme();

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/projects'), ['name' => 'Marketing Site'])
            ->assertRedirect();

        $project = Project::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(
            array_column(Status::DEFAULTS, 'name'),
            $this->statusesOf($project)->pluck('name')->all(),
        );
        $this->assertCount(0, $this->fieldsOf($project), 'The default workflow brings no fields.');
    }

    #[Test]
    public function a_template_can_be_chosen_on_the_create_form(): void
    {
        [$workspace, $user] = $this->acme();

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/projects'), [
                'name' => 'Acme Shop',
                'template' => 'client_website',
            ])
            ->assertRedirect();

        $project = Project::withoutGlobalScopes()->firstOrFail();
        $names = $this->statusesOf($project)->pluck('name')->all();

        $this->assertContains('Awaiting client', $names);
        $this->assertNotSame(array_column(Status::DEFAULTS, 'name'), $names);
        $this->assertContains('Client reference', $this->fieldsOf($project)->pluck('name')->all());
    }

    #[Test]
    public function an_unknown_template_is_refused_rather_than_quietly_replaced(): void
    {
        [$workspace, $user] = $this->acme();

        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/projects'), [
                'name' => 'Acme Shop',
                'template' => 'no_such_template',
            ])
            ->assertSessionHasErrors('template');

        $this->assertSame(0, Project::withoutGlobalScopes()->count());

        // Positive control: the same request with a real key goes through.
        $this->actingAs($user)
            ->post($this->workspaceUrl($workspace, '/projects'), [
                'name' => 'Acme Shop',
                'template' => 'support',
            ])
            ->assertRedirect();

        $this->assertSame(1, Project::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_template_creates_missing_labels_and_leaves_existing_ones_alone(): void
    {
        [$workspace] = $this->acme();

        $existing = app(Tenancy::class)->run($workspace, fn () => Label::create([
            'name' => 'Regression',
            'color' => '#123456',
            'description' => 'Ours, thanks.',
        ]));

        $this->create($workspace, ['name' => 'Retainer', 'template' => 'support']);

        $existing->refresh();

        $this->assertSame('#123456', $existing->color, 'A template must not recolour a label somebody already owns.');
        $this->assertSame('Ours, thanks.', $existing->description);
        $this->assertSame(
            1,
            Label::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('name', 'Regression')->count(),
            'The label should not have been duplicated.',
        );

        // Positive control: the labels that were missing did get created.
        $this->assertDatabaseHas('labels', ['workspace_id' => $workspace->id, 'name' => 'Out of hours']);
    }

    #[Test]
    public function everything_a_template_creates_belongs_to_the_workspace(): void
    {
        [$workspace] = $this->acme();

        $project = $this->create($workspace, ['name' => 'Retainer', 'template' => 'support']);

        $this->assertDatabaseMissing('statuses', ['workspace_id' => null]);
        $this->assertDatabaseMissing('custom_fields', ['workspace_id' => null]);
        $this->assertDatabaseMissing('labels', ['workspace_id' => null]);

        $this->assertTrue(
            $this->statusesOf($project)->every(fn (Status $s) => $s->workspace_id === $workspace->id),
        );
        $this->assertTrue(
            $this->fieldsOf($project)->every(fn (CustomField $f) => $f->workspace_id === $workspace->id),
        );
    }

    // ------------------------------------------------------- copying another project

    /**
     * A project with a workflow, fields, issues, values, a client and a widget key —
     * so that "none of that came across" has something to not come across.
     *
     * @return array{0: Project, 1: CustomField, 2: User}
     */
    private function sourceProject(Workspace $workspace): array
    {
        return app(Tenancy::class)->run($workspace, function () use ($workspace) {
            $source = app(CreateProject::class)->handle([
                'name' => 'Acme Shop',
                'template' => 'client_website',
            ]);

            // A client-visible field, so the copy has a decision to preserve.
            $shared = CustomField::create([
                'project_id' => $source->id,
                'name' => 'Client reference',
                'key' => 'client_ref',
                'type' => 'text',
                'required' => true,
                'visible_to_client' => true,
                'position' => 9,
            ]);

            $issue = Issue::factory()->create(['project_id' => $source->id]);

            CustomFieldValue::create([
                'issue_id' => $issue->id,
                'custom_field_id' => $shared->id,
                'value' => 'AC-1120',
            ]);

            WidgetKey::factory()->create(['project_id' => $source->id]);

            $client = User::factory()->create();
            $workspace->members()->attach($client->id, [
                'role' => WorkspaceRole::Client->value,
                'joined_at' => now(),
            ]);
            $source->clients()->attach($client->id, ['role' => 'client']);

            return [$source, $shared, $client];
        });
    }

    #[Test]
    public function copying_a_project_reproduces_its_workflow_and_fields(): void
    {
        [$workspace] = $this->acme();
        [$source] = $this->sourceProject($workspace);

        // Something the source was edited into that no template would produce, so a
        // pass cannot come from both projects happening to use the same template.
        app(Tenancy::class)->run($workspace, function () use ($source) {
            Status::where('project_id', $source->id)->where('name', 'Building')
                ->update(['name' => 'Under construction', 'color' => '#abcdef', 'wip_limit' => 4]);
        });

        $copy = $this->create($workspace, [
            'name' => 'Globex Shop',
            'source_project_id' => $source->id,
        ]);

        $expected = $this->statusesOf($source);
        $actual = $this->statusesOf($copy);

        $this->assertSame($expected->pluck('name')->all(), $actual->pluck('name')->all());
        $this->assertSame($expected->pluck('category')->all(), $actual->pluck('category')->all());
        $this->assertSame($expected->pluck('color')->all(), $actual->pluck('color')->all());
        $this->assertSame($expected->pluck('is_default')->all(), $actual->pluck('is_default')->all());
        $this->assertSame($expected->pluck('wip_limit')->all(), $actual->pluck('wip_limit')->all());
        $this->assertContains('Under construction', $actual->pluck('name')->all());

        $expectedFields = $this->fieldsOf($source);
        $copiedFields = $this->fieldsOf($copy);

        $this->assertSame($expectedFields->pluck('name')->all(), $copiedFields->pluck('name')->all());
        $this->assertSame(
            $expectedFields->pluck('key')->all(),
            $copiedFields->pluck('key')->all(),
            'The keys carry over, or a saved view filtering on one stops working.',
        );
        $this->assertSame($expectedFields->pluck('type')->all(), $copiedFields->pluck('type')->all());
        $this->assertSame($expectedFields->pluck('required')->all(), $copiedFields->pluck('required')->all());
        $this->assertSame(
            $expectedFields->pluck('visible_to_client')->all(),
            $copiedFields->pluck('visible_to_client')->all(),
            'A decision made about a field on the source project is part of the setup.',
        );

        // The copy is its own project, not a second name for the first one.
        $this->assertNotSame($source->id, $copy->id);
        $this->assertNotSame($source->key, $copy->key);
        $this->assertTrue($actual->pluck('id')->intersect($expected->pluck('id'))->isEmpty());
    }

    #[Test]
    public function copying_a_project_carries_none_of_its_issues(): void
    {
        [$workspace] = $this->acme();
        [$source] = $this->sourceProject($workspace);

        $copy = $this->create($workspace, ['name' => 'Globex Shop', 'source_project_id' => $source->id]);

        // Positive control: there were issues to copy, and setup that did copy.
        $this->assertSame(1, Issue::withoutGlobalScopes()->where('project_id', $source->id)->count());
        $this->assertGreaterThan(0, $this->statusesOf($copy)->count());

        $this->assertSame(0, Issue::withoutGlobalScopes()->where('project_id', $copy->id)->count());
    }

    #[Test]
    public function copying_a_project_carries_no_field_values(): void
    {
        [$workspace] = $this->acme();
        [$source, $shared] = $this->sourceProject($workspace);

        $copy = $this->create($workspace, ['name' => 'Globex Shop', 'source_project_id' => $source->id]);

        // Positive control: a value exists on the source, and the definition that
        // holds it did come across.
        $this->assertSame(1, CustomFieldValue::withoutGlobalScopes()->where('custom_field_id', $shared->id)->count());
        $this->assertContains('Client reference', $this->fieldsOf($copy)->pluck('name')->all());

        $copiedFieldIds = $this->fieldsOf($copy)->pluck('id');

        $this->assertSame(
            0,
            CustomFieldValue::withoutGlobalScopes()->whereIn('custom_field_id', $copiedFieldIds)->count(),
            'A definition is setup; a value is work.',
        );
    }

    #[Test]
    public function copying_a_project_carries_no_client_grants(): void
    {
        [$workspace] = $this->acme();
        [$source, , $client] = $this->sourceProject($workspace);

        $copy = $this->create($workspace, ['name' => 'Globex Shop', 'source_project_id' => $source->id]);

        // Positive control: the client still holds the source, and the copy is a
        // real project rather than an empty row.
        $this->assertDatabaseHas('project_user', ['project_id' => $source->id, 'user_id' => $client->id]);
        $this->assertGreaterThan(0, $this->statusesOf($copy)->count());

        $this->assertDatabaseMissing('project_user', ['project_id' => $copy->id]);

        // And the copy does not exist as far as they are concerned, which is what the
        // grant would have changed.
        $visible = app(Tenancy::class)->run(
            $workspace,
            fn () => Project::visibleTo($client)->pluck('id')->all(),
        );

        $this->assertContains($source->id, $visible);
        $this->assertNotContains($copy->id, $visible);
    }

    #[Test]
    public function copying_a_project_carries_no_widget_keys(): void
    {
        [$workspace] = $this->acme();
        [$source] = $this->sourceProject($workspace);

        $copy = $this->create($workspace, ['name' => 'Globex Shop', 'source_project_id' => $source->id]);

        // Positive control: the source has one, and the copy did get its setup.
        $this->assertSame(1, WidgetKey::withoutGlobalScopes()->where('project_id', $source->id)->count());
        $this->assertGreaterThan(0, $this->fieldsOf($copy)->count());

        $this->assertSame(
            0,
            WidgetKey::withoutGlobalScopes()->where('project_id', $copy->id)->count(),
            'A widget key is a credential with its own origin allowlist.',
        );
    }

    #[Test]
    public function a_project_in_another_workspace_cannot_be_copied_from(): void
    {
        [$acme, $owner] = $this->acme('acme');
        [$globex] = $this->acme('globex');

        [$theirs] = $this->sourceProject($globex);

        $this->actingAs($owner)
            ->post($this->workspaceUrl($acme, '/projects'), [
                'name' => 'Acme Shop',
                'source_project_id' => $theirs->id,
            ])
            ->assertSessionHasErrors('source_project_id');

        $this->assertSame(
            0,
            Project::withoutGlobalScopes()->where('workspace_id', $acme->id)->count(),
            'Nothing should have been created from another workspace\'s project.',
        );

        // Positive control: the identical request naming one of our own works, so the
        // refusal is about the workspace and not about copying at all.
        [$ours] = $this->sourceProject($acme);

        $this->actingAs($owner)
            ->post($this->workspaceUrl($acme, '/projects'), [
                'name' => 'Acme Two',
                'source_project_id' => $ours->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $copy = Project::withoutGlobalScopes()->where('name', 'Acme Two')->firstOrFail();

        $this->assertGreaterThan(0, $this->statusesOf($copy)->count());
        $this->assertSame($acme->id, $copy->workspace_id);
    }

    #[Test]
    public function the_create_screen_offers_templates_and_only_this_workspaces_projects(): void
    {
        [$acme, $owner] = $this->acme('acme');
        [$globex] = $this->acme('globex');

        $this->create($acme, ['name' => 'Acme Shop']);
        $this->create($globex, ['name' => 'Globex Secret Rebrand']);

        $this->actingAs($owner)
            ->get($this->workspaceUrl($acme, '/projects/create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('templates', count(config('templates.templates')))
                ->where('templates.0.key', config('templates.default'))
                ->where('defaultTemplate', config('templates.default'))
                ->has('sources', 1)
                ->where('sources.0.name', 'Acme Shop'))
            // One workspace learning another's project names is the leak, whether or
            // not anything could be copied from it.
            ->assertDontSee('Globex Secret Rebrand');
    }

    #[Test]
    public function copying_a_project_whose_workflow_is_broken_is_refused(): void
    {
        [$workspace] = $this->acme();
        [$source] = $this->sourceProject($workspace);

        // Forced past the workflow editor, which would refuse this — the guard on the
        // copy has to hold whatever route the rows arrived by.
        app(Tenancy::class)->run($workspace, function () use ($source) {
            Status::where('project_id', $source->id)->update(['category' => StatusCategory::Done->value]);
        });

        $before = Project::withoutGlobalScopes()->count();

        try {
            $this->create($workspace, ['name' => 'Globex Shop', 'source_project_id' => $source->id]);
            $this->fail('Copying a workflow with no open status should have been refused.');
        } catch (InvalidTemplate $e) {
            $this->assertStringContainsString('open', $e->getMessage());
        }

        $this->assertSame($before, Project::withoutGlobalScopes()->count(), 'The project row should have rolled back.');
    }

    // ------------------------------------------------------------ malformed templates

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function malformedTemplates(): array
    {
        $ok = [
            ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
            ['name' => 'Done', 'category' => 'done', 'color' => '#10b981'],
        ];

        return [
            'no statuses at all' => [
                ['name' => 'Broken', 'statuses' => []],
                'defines no statuses',
            ],
            'nothing open' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Done', 'category' => 'done', 'color' => '#10b981', 'is_default' => true],
                ]],
                'no status is in an open category',
            ],
            'nothing done' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                    ['name' => 'Dropped', 'category' => 'canceled', 'color' => '#6b7280'],
                ]],
                'no status is in the done category',
            ],
            'no default' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b'],
                    ['name' => 'Done', 'category' => 'done', 'color' => '#10b981'],
                ]],
                'statuses are marked as the default',
            ],
            'two defaults' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                    ['name' => 'Next', 'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                    ['name' => 'Done', 'category' => 'done', 'color' => '#10b981'],
                ]],
                'statuses are marked as the default',
            ],
            'the default is closed' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b'],
                    ['name' => 'Done', 'category' => 'done', 'color' => '#10b981', 'is_default' => true],
                ]],
                'new issues cannot start closed',
            ],
            'an invented category' => [
                ['name' => 'Broken', 'statuses' => [
                    ...$ok,
                    ['name' => 'Pending', 'category' => 'pending', 'color' => '#64748b'],
                ]],
                'no valid category',
            ],
            'two statuses with one name' => [
                ['name' => 'Broken', 'statuses' => [...$ok, ['name' => 'todo', 'category' => 'started', 'color' => '#64748b']]],
                'two statuses are named',
            ],
            'a colour that is not one' => [
                ['name' => 'Broken', 'statuses' => [
                    ['name' => 'Todo', 'category' => 'unstarted', 'color' => 'slate', 'is_default' => true],
                    ['name' => 'Done', 'category' => 'done', 'color' => '#10b981'],
                ]],
                'six-digit hex colour',
            ],
            'an invented field type' => [
                ['name' => 'Broken', 'statuses' => $ok, 'fields' => [['name' => 'Browser', 'type' => 'wysiwyg']]],
                'no valid type',
            ],
            'a choice field with no choices' => [
                ['name' => 'Broken', 'statuses' => $ok, 'fields' => [['name' => 'Size', 'type' => 'select']]],
                'has no choices',
            ],
            'a field shown to clients' => [
                ['name' => 'Broken', 'statuses' => $ok, 'fields' => [
                    ['name' => 'Estimate', 'type' => 'number', 'visible_to_client' => true],
                ]],
                'Template fields are always internal',
            ],
            'not an array' => [
                'client_website',
                'it is not an array',
            ],
        ];
    }

    #[Test]
    #[DataProvider('malformedTemplates')]
    public function a_malformed_template_is_refused_rather_than_half_applied(mixed $definition, string $because): void
    {
        [$workspace] = $this->acme();

        config()->set('templates.templates.broken', $definition);

        try {
            $this->create($workspace, ['name' => 'Doomed', 'template' => 'broken']);
            $this->fail("A template that {$because} should not have created a project.");
        } catch (InvalidTemplate $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }

        // Atomic: no project, and nothing half-written beside it.
        $this->assertSame(0, Project::withoutGlobalScopes()->count());
        $this->assertSame(0, Status::withoutGlobalScopes()->count());
        $this->assertSame(0, CustomField::withoutGlobalScopes()->count());
        $this->assertSame(0, Label::withoutGlobalScopes()->count());

        // Positive control: with the template repaired, the very same call works, so
        // the refusal is about the template and not about the request.
        config()->set('templates.templates.broken', [
            'name' => 'Repaired',
            'statuses' => [
                ['name' => 'Todo', 'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
                ['name' => 'Done', 'category' => 'done', 'color' => '#10b981'],
            ],
            'labels' => [['name' => 'Repaired', 'color' => '#10b981']],
            'fields' => [['name' => 'Estimate', 'type' => 'number']],
        ]);

        $project = $this->create($workspace, ['name' => 'Doomed', 'template' => 'broken']);

        // New is added to every workflow that lacks one: where a client's issue starts.
        $this->assertSame(['New', 'Todo', 'Done'], $this->statusesOf($project)->pluck('name')->all());
        $this->assertSame(['Estimate'], $this->fieldsOf($project)->pluck('name')->all());
        $this->assertDatabaseHas('labels', ['workspace_id' => $workspace->id, 'name' => 'Repaired']);
    }

    #[Test]
    public function a_default_that_names_no_template_is_refused(): void
    {
        [$workspace] = $this->acme();

        config()->set('templates.default', 'gone');

        try {
            $this->create($workspace, ['name' => 'Doomed']);
            $this->fail('A missing default template should not have created a project.');
        } catch (InvalidTemplate $e) {
            $this->assertStringContainsString('[gone]', $e->getMessage());
        }

        $this->assertSame(0, Project::withoutGlobalScopes()->count());
    }
}
