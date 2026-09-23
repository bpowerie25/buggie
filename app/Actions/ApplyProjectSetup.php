<?php

namespace App\Actions;

use App\Enums\StatusCategory;
use App\Models\CustomField;
use App\Models\Label;
use App\Models\Project;
use App\Models\Status;
use App\Support\Templates\InvalidTemplate;
use App\Support\Templates\ProjectTemplate;

/**
 * Give a brand-new project its statuses, labels and custom field definitions.
 *
 * Two ways in, one outcome. A built-in template comes from config/templates.php;
 * "make it like Acme's" comes from another project in the same workspace, which is
 * the case an agency actually has — the setup they want already exists and was
 * arrived at over three client projects rather than designed.
 *
 * What is deliberately not copied, and why, is in copyFrom().
 *
 * Callers run this inside the transaction that created the project. A half-applied
 * setup — statuses but no fields, or four of seven statuses — is worse than none,
 * because it looks finished.
 */
class ApplyProjectSetup
{
    public function fromTemplate(Project $project, ProjectTemplate $template): void
    {
        foreach ($template->statuses as $position => $status) {
            $project->statuses()->create([
                'name' => $status['name'],
                'category' => $status['category']->value,
                'color' => $status['color'],
                'is_default' => $status['is_default'],
                'wip_limit' => $status['wip_limit'],
                'position' => $position,
            ]);
        }

        foreach ($template->labels as $label) {
            /*
             * Labels are workspace-wide, so a template adds the ones that are
             * missing and leaves the rest alone. Recolouring somebody's existing
             * "Regression" because a template has an opinion about it would change
             * every project in the workspace on the strength of a dropdown.
             */
            Label::firstOrCreate(
                ['name' => $label['name']],
                ['color' => $label['color'], 'description' => $label['description']],
            );
        }

        foreach ($template->fields as $position => $field) {
            CustomField::create([
                'project_id' => $project->id,
                'name' => $field['name'],
                'key' => CustomField::uniqueKeyFor($project->id, $field['name']),
                'type' => $field['type']->value,
                'options' => $field['options'],
                'required' => $field['required'],

                // Never from the template. A field reaches a client because somebody
                // decided it should, which is a decision made on the project, not one
                // inherited from a list they picked in a hurry.
                'visible_to_client' => false,
                'position' => $position,
            ]);
        }

        $this->assertUsableWorkflow($project);
    }

    /**
     * Copy another project's setup.
     *
     * Copied: the statuses, exactly — names, categories, colours, order, which one
     * is the default, and any WIP limit. The custom field definitions, keeping their
     * keys so a saved view or a CSV built around `field:client_ref` works on the new
     * project too.
     *
     * Not copied, and none of these are oversights:
     *
     * - **Issues, comments and attachments.** This is a new project, not a fork of
     *   somebody else's work.
     * - **Custom field values.** They belong to issues that are not being copied; a
     *   definition is setup, a value is work.
     * - **Client grants.** A client holding the source project must not silently
     *   acquire a project nobody has decided to show them. That is the whole of the
     *   second gate in the visibility rules.
     * - **Widget keys.** A key is a credential with its own origin allowlist, and
     *   two projects sharing one would file every report against whichever was
     *   copied from. New project, new key, made deliberately.
     * - **Versions, webhooks, branding and the inbound mail token.** Per-project
     *   things that name the project they belong to.
     *
     * Labels need no copying at all: they belong to the workspace, so the source
     * project's labels are already available to the new one.
     */
    public function copyFrom(Project $source, Project $target): void
    {
        /*
         * Belt and braces. The request validates the source id against the current
         * workspace by hand, and the global scope means a foreign id cannot be found
         * in the first place — but this is the line that would stamp another
         * workspace's names onto our rows, so it checks rather than assumes.
         */
        if ($source->workspace_id !== $target->workspace_id) {
            throw InvalidTemplate::workflow(
                "project [{$source->id}] belongs to another workspace and cannot be copied from."
            );
        }

        foreach ($source->statuses()->orderBy('position')->orderBy('id')->get() as $position => $status) {
            $target->statuses()->create([
                'name' => $status->name,
                'category' => $status->category->value,
                'color' => $status->color,
                'is_default' => $status->is_default,
                'wip_limit' => $status->wip_limit,

                // Renumbered from zero rather than carried across: the source may
                // have gaps from deleted statuses, and order is all that matters.
                'position' => $position,
            ]);
        }

        foreach (CustomField::where('project_id', $source->id)->inOrder()->get() as $position => $field) {
            CustomField::create([
                'project_id' => $target->id,
                'name' => $field->name,
                'key' => CustomField::uniqueKeyFor($target->id, $field->key),
                'type' => $field->type->value,
                'options' => $field->options,
                'required' => $field->required,

                /*
                 * Kept, unlike a template's. Somebody in this workspace already
                 * decided this field is one a client may see, on a project they were
                 * looking at; "make it like Acme's" that quietly made it internal
                 * would be its own surprise. No client can see it yet regardless —
                 * grants are not copied, so nobody holds the new project.
                 */
                'visible_to_client' => $field->visible_to_client,
                'position' => $position,
            ]);
        }

        $this->assertUsableWorkflow($target);
    }

    /**
     * What the project actually ended up with, asked of the database.
     *
     * The template loader checks the same three things before anything is written.
     * This checks them afterwards, on both paths, because it is the answer that
     * matters and not the intention — and because the copy path has no config file
     * to have been validated.
     */
    private function assertUsableWorkflow(Project $project): void
    {
        $statuses = $project->statuses()->get();

        if ($statuses->isEmpty()) {
            throw InvalidTemplate::workflow('the project would have no statuses at all.');
        }

        if ($statuses->filter(fn (Status $s) => $s->category->isOpen())->isEmpty()) {
            throw InvalidTemplate::workflow('no status is open, so a new issue would have nowhere to start.');
        }

        if ($statuses->filter(fn (Status $s) => $s->category === StatusCategory::Done)->isEmpty()) {
            throw InvalidTemplate::workflow('no status is in the done category, so nothing could ever be resolved.');
        }

        $defaults = $statuses->filter(fn (Status $s) => $s->is_default);

        if ($defaults->count() !== 1) {
            throw InvalidTemplate::workflow(
                $defaults->count().' statuses are marked as the default; a project needs exactly one.'
            );
        }

        if (! $defaults->first()->category->isOpen()) {
            throw InvalidTemplate::workflow('the default status is closed; new issues cannot start closed.');
        }
    }
}
