<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\CustomFieldType;
use App\Enums\WorkspaceRole;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\CustomFields\FieldValues;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Project} */
    private function project(WorkspaceRole $role = WorkspaceRole::Owner): array
    {
        [$workspace, $user] = $this->workspaceWithMember($role, 'acme');

        $project = app(Tenancy::class)->run(
            $workspace,
            fn () => app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']),
        );

        return [$workspace, $user, $project];
    }

    // ------------------------------------------------------------- definitions

    #[Test]
    public function a_field_can_be_added_to_a_project(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields"), [
                'name' => 'Client reference',
                'type' => 'text',
            ])
            ->assertRedirect();

        $field = CustomField::withoutGlobalScopes()->first();

        $this->assertSame('Client reference', $field->name);
        $this->assertSame('client_reference', $field->key, 'The key is derived from the name.');
        $this->assertFalse($field->visible_to_client, 'Internal unless chosen otherwise.');
    }

    #[Test]
    public function two_fields_with_the_same_name_do_not_collide(): void
    {
        // The unique index would otherwise turn a reasonable accident into a 500.
        [$workspace, $owner, $project] = $this->project();

        foreach (['Browser', 'Browser'] as $name) {
            $this->actingAs($owner)
                ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields"), [
                    'name' => $name, 'type' => 'text',
                ])
                ->assertRedirect();
        }

        $keys = CustomField::withoutGlobalScopes()->pluck('key')->all();

        $this->assertCount(2, $keys);
        $this->assertSame($keys, array_unique($keys));
    }

    #[Test]
    public function the_key_does_not_change_when_the_field_is_renamed(): void
    {
        // A saved view filtering on field:client_reference and a spreadsheet built
        // around the CSV header both break silently if it does.
        [$workspace, $owner, $project] = $this->project();

        $field = app(Tenancy::class)->run($workspace, fn () => CustomField::factory()->create([
            'project_id' => $project->id, 'name' => 'Client reference', 'key' => 'client_reference',
        ]));

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields/{$field->id}"), [
                'name' => 'Client ref', 'type' => 'text', 'key' => 'something_else',
            ])
            ->assertRedirect();

        $field->refresh();

        $this->assertSame('Client ref', $field->name);
        $this->assertSame('client_reference', $field->key);
    }

    #[Test]
    public function a_choice_field_needs_choices(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields"), [
                'name' => 'Environment', 'type' => 'select', 'options' => [],
            ])
            ->assertSessionHasErrors('options');
    }

    #[Test]
    public function only_someone_who_can_edit_the_project_can_define_fields(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);
        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields"), [
                'name' => 'Sneaky', 'type' => 'text',
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ values

    #[Test]
    public function a_value_is_stored_against_an_issue(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create([
                'project_id' => $project->id, 'name' => 'Browser', 'key' => 'browser',
            ]);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['browser' => 'Safari 18'],
            ], $owner);

            $this->assertSame('Safari 18', CustomFieldValue::where('issue_id', $issue->id)->value('value'));
        });
    }

    #[Test]
    public function a_number_field_refuses_prose(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'estimate',
                'type' => CustomFieldType::Number->value,
            ]);

            $this->expectException(ValidationException::class);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['estimate' => 'quite a lot'],
            ], $owner);
        });
    }

    #[Test]
    public function a_choice_field_refuses_a_value_that_is_not_one_of_its_choices(): void
    {
        // Otherwise it is a text box wearing a dropdown and the export grows a column
        // of one-off spellings.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->select(['Production', 'Staging'])->create([
                'project_id' => $project->id, 'key' => 'environment',
            ]);

            $this->expectException(ValidationException::class);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['environment' => 'Wherever'],
            ], $owner);
        });
    }

    #[Test]
    public function a_link_field_refuses_a_javascript_url(): void
    {
        // The value is rendered as an anchor a member of the team will click.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'spec',
                'type' => CustomFieldType::Url->value,
            ]);

            $this->expectException(ValidationException::class);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['spec' => 'javascript:alert(1)'],
            ], $owner);
        });
    }

    #[Test]
    public function a_link_field_accepts_an_ordinary_url(): void
    {
        // The paired positive control: a rule that rejected everything would pass the
        // test above.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'spec',
                'type' => CustomFieldType::Url->value,
            ]);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['spec' => 'https://example.com/spec'],
            ], $owner);

            $this->assertSame(
                'https://example.com/spec',
                CustomFieldValue::where('issue_id', $issue->id)->value('value'),
            );
        });
    }

    #[Test]
    public function a_required_field_is_required_when_a_person_files_an_issue(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'environment', 'required' => true,
            ]);

            $this->expectException(ValidationException::class);

            app(CreateIssue::class)->handle($project, ['title' => 'Broken'], $owner);
        });
    }

    #[Test]
    public function a_required_field_does_not_block_a_bulk_status_change(): void
    {
        // The issues already exist. Refusing to move one across the board because a
        // field added afterwards is empty would make the board unusable.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            $issue = app(CreateIssue::class)->handle($project, ['title' => 'Broken'], $owner);

            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'environment', 'required' => true,
            ]);

            $done = \App\Models\Status::where('project_id', $project->id)->get()->last();

            app(\App\Actions\UpdateIssue::class)->handle($issue, ['status_id' => $done->id], $owner);

            $this->assertSame($done->id, $issue->refresh()->status_id);
        });
    }

    #[Test]
    public function an_unknown_key_is_ignored_rather_than_rejected(): void
    {
        // An import or an older API client sending a field somebody has since deleted
        // should not have its whole request fail over it.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['deleted_field' => 'whatever'],
            ], $owner);

            $this->assertSame(0, CustomFieldValue::where('issue_id', $issue->id)->count());
        });
    }

    #[Test]
    public function deleting_a_field_takes_its_values(): void
    {
        [$workspace, $owner, $project] = $this->project();

        $field = app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            $field = CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'browser',
            ]);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['browser' => 'Safari'],
            ], $owner);

            return $field;
        });

        $this->assertSame(1, CustomFieldValue::withoutGlobalScopes()->count());

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/projects/{$project->slug}/fields/{$field->id}"))
            ->assertRedirect();

        $this->assertSame(0, CustomFieldValue::withoutGlobalScopes()->count());
    }

    #[Test]
    public function editing_one_field_leaves_the_others_alone(): void
    {
        // The sidebar patches a single field. An earlier version of this resolved
        // every unmentioned field to null and deleted it, so changing the browser
        // silently wiped the client reference beside it.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'browser']);
            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'client_ref']);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken',
                'custom_fields' => ['browser' => 'Safari', 'client_ref' => 'AC-1120'],
            ], $owner);

            app(\App\Actions\UpdateIssue::class)->handle($issue, [
                'custom_fields' => ['browser' => 'Firefox'],
            ], $owner);

            $values = app(FieldValues::class)->forIssue($issue->refresh());
            $byKey = collect($values)->keyBy('key');

            $this->assertSame('Firefox', $byKey['browser']['value']);
            $this->assertSame('AC-1120', $byKey['client_ref']['value'], 'The untouched field was wiped.');
        });
    }

    #[Test]
    public function a_field_can_still_be_cleared_on_purpose(): void
    {
        // The paired control for the test above: if "absent means leave alone" were
        // implemented as "never delete", clearing a field would stop working.
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'browser']);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['browser' => 'Safari'],
            ], $owner);

            app(\App\Actions\UpdateIssue::class)->handle($issue, [
                'custom_fields' => ['browser' => null],
            ], $owner);

            $this->assertSame(0, CustomFieldValue::where('issue_id', $issue->id)->count());
        });
    }

    #[Test]
    public function the_sidebar_patch_reaches_the_database(): void
    {
        // Exactly what the issue page sends when one field is changed: a PATCH
        // carrying a single-key map. Asserted over HTTP rather than against the
        // action, because the request rules are part of the path and "sometimes"
        // on the wrong key would drop it silently.
        [$workspace, $owner, $project] = $this->project();

        $issue = app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->select(['Production', 'Staging'])->create([
                'project_id' => $project->id, 'key' => 'environment',
            ]);

            return app(CreateIssue::class)->handle($project, ['title' => 'Broken'], $owner);
        });

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/issues/{$issue->key}"), [
                'custom_fields' => ['environment' => 'Production'],
            ])
            ->assertRedirect();

        $this->assertSame(
            'Production',
            CustomFieldValue::withoutGlobalScopes()->where('issue_id', $issue->id)->value('value'),
        );
    }

    // ------------------------------------------------------------------ leaking

    #[Test]
    public function an_internal_field_never_reaches_a_clients_browser(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            CustomField::factory()->create([
                'project_id' => $project->id, 'name' => 'Internal estimate', 'key' => 'estimate',
            ]);
            CustomField::factory()->clientVisible()->create([
                'project_id' => $project->id, 'name' => 'Client reference', 'key' => 'client_ref',
            ]);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Broken',
                'visibility' => 'client',
                'custom_fields' => ['estimate' => 'THREE-WEEKS-SECRET', 'client_ref' => 'AC-1120'],
            ], $staff);

            return [$project, $issue];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $html = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->getContent();

        // Not merely unrendered — absent from the payload.
        $this->assertStringNotContainsString('THREE-WEEKS-SECRET', $html);
        $this->assertStringNotContainsString('Internal estimate', $html);

        // The positive control: without it, a page that rendered nothing at all
        // would pass.
        $this->assertStringContainsString('AC-1120', $html);
    }

    #[Test]
    public function staff_see_both(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'estimate', 'name' => 'Internal estimate']);

            return app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['estimate' => 'THREE-WEEKS-SECRET'],
            ], $staff);
        });

        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertSee('THREE-WEEKS-SECRET');
    }

    #[Test]
    public function a_client_cannot_filter_on_an_internal_field(): void
    {
        // Filtering leaks a value just as surely as printing it: try values, watch
        // the result count.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            CustomField::factory()->create([
                'project_id' => $project->id, 'key' => 'estimate', 'name' => 'Internal estimate',
            ]);

            $issue = app(CreateIssue::class)->handle($project, [
                'title' => 'Findable', 'visibility' => 'client',
                'custom_fields' => ['estimate' => 'three-weeks'],
            ], $staff);

            return [$project, $issue];
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        // Staff can find it by that filter.
        $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('field:estimate=three-weeks')))
            ->assertOk()
            ->assertSee('Findable');

        // The client cannot — the filter matches nothing rather than matching the
        // internal field.
        $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('field:estimate=three-weeks')))
            ->assertOk()
            ->assertDontSee('Findable');
    }

    // ------------------------------------------------------------------ filtering

    #[Test]
    public function issues_can_be_filtered_by_a_field_value(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->select(['Production', 'Staging'])->create([
                'project_id' => $project->id, 'key' => 'environment',
            ]);

            app(CreateIssue::class)->handle($project, [
                'title' => 'On production', 'custom_fields' => ['environment' => 'Production'],
            ], $owner);

            app(CreateIssue::class)->handle($project, [
                'title' => 'On staging', 'custom_fields' => ['environment' => 'Staging'],
            ], $owner);
        });

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('field:environment=Production')))
            ->assertOk()
            ->assertSee('On production')
            ->assertDontSee('On staging');
    }

    #[Test]
    public function a_field_filter_can_be_negated(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->select(['Production', 'Staging'])->create([
                'project_id' => $project->id, 'key' => 'environment',
            ]);

            app(CreateIssue::class)->handle($project, [
                'title' => 'On production', 'custom_fields' => ['environment' => 'Production'],
            ], $owner);

            app(CreateIssue::class)->handle($project, [
                'title' => 'On staging', 'custom_fields' => ['environment' => 'Staging'],
            ], $owner);
        });

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues?q='.urlencode('-field:environment=Production')))
            ->assertOk()
            ->assertSee('On staging')
            ->assertDontSee('On production');
    }

    #[Test]
    public function the_export_carries_field_columns_but_only_visible_ones(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $project = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $project = app(\App\Actions\CreateProject::class)->handle(['name' => 'Site']);

            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'estimate']);
            CustomField::factory()->clientVisible()->create(['project_id' => $project->id, 'key' => 'client_ref']);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'visibility' => 'client',
                'custom_fields' => ['estimate' => 'SECRET-ESTIMATE', 'client_ref' => 'AC-1120'],
            ], $staff);

            return $project;
        });

        $project->clients()->attach($client->id, ['role' => 'client']);

        $staffCsv = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, '/issues/export'))
            ->streamedContent();

        $this->assertStringContainsString('field:estimate', $staffCsv);
        $this->assertStringContainsString('SECRET-ESTIMATE', $staffCsv);

        $clientCsv = $this->actingAs($client)
            ->get($this->workspaceUrl($workspace, '/issues/export'))
            ->streamedContent();

        $this->assertStringNotContainsString('field:estimate', $clientCsv);
        $this->assertStringNotContainsString('SECRET-ESTIMATE', $clientCsv);
        $this->assertStringContainsString('AC-1120', $clientCsv, 'The positive control.');
    }

    #[Test]
    public function a_field_value_cannot_smuggle_a_spreadsheet_formula(): void
    {
        [$workspace, $owner, $project] = $this->project();

        app(Tenancy::class)->run($workspace, function () use ($project, $owner) {
            CustomField::factory()->create(['project_id' => $project->id, 'key' => 'note']);

            app(CreateIssue::class)->handle($project, [
                'title' => 'Broken', 'custom_fields' => ['note' => '=1+1'],
            ], $owner);
        });

        $csv = $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/issues/export'))
            ->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }
}
