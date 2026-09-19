<?php

namespace App\Http\Controllers;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Defining a project's custom fields.
 *
 * Managing the definitions is a project-settings job, so it is gated on updating the
 * project — the same permission that renames statuses and edits branding. Filling
 * them in is an issue job and lives with the issue.
 */
class CustomFieldController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $this->validated($request, $project);

        CustomField::create([
            'project_id' => $project->id,
            ...$validated,
            'position' => (int) CustomField::where('project_id', $project->id)->max('position') + 1,
        ]);

        return back()->with('success', "Added the field “{$validated['name']}”.");
    }

    public function update(Request $request, Project $project, CustomField $field): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($field->project_id === $project->id, 404);

        $validated = $this->validated($request, $project, $field);

        // The key is deliberately not editable after creation. A saved view filtering
        // on `field:client_ref=...` and a CSV somebody built a spreadsheet around both
        // break silently when it changes, and renaming the label covers what people
        // actually want.
        unset($validated['key']);

        $field->update($validated);

        return back()->with('success', 'Field updated.');
    }

    public function destroy(Project $project, CustomField $field): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($field->project_id === $project->id, 404);

        // The values go with it, by foreign key. Said plainly in the UI before
        // anybody presses this, because it is not recoverable.
        $field->delete();

        return back()->with('success', 'Field deleted, along with its values.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?CustomField $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            'options' => ['array', 'max:50'],
            'options.*' => ['required', 'string', 'max:120'],
            'required' => ['boolean'],
            'visible_to_client' => ['boolean'],
        ]);

        $type = CustomFieldType::from($validated['type']);

        // Options on a text field would be stored, never shown, and confuse whoever
        // read the row next.
        $options = $type->hasOptions()
            ? array_values(array_unique(array_filter($validated['options'] ?? [])))
            : null;

        if ($type->hasOptions() && $options === []) {
            return throw \Illuminate\Validation\ValidationException::withMessages([
                'options' => 'A choice field needs at least one choice.',
            ]);
        }

        $key = $existing?->key ?? $this->uniqueKey($project, $validated['name']);

        return [
            'name' => $validated['name'],
            'key' => $key,
            'type' => $type->value,
            'options' => $options,
            'required' => $request->boolean('required'),
            'visible_to_client' => $request->boolean('visible_to_client'),
        ];
    }

    /**
     * A key nothing else on this project is using.
     *
     * Two fields named "Browser" is a reasonable thing to do by accident, and the
     * unique index would otherwise turn it into a 500.
     */
    private function uniqueKey(Project $project, string $name): string
    {
        $base = CustomField::keyFrom($name);
        $key = $base;
        $suffix = 2;

        while (CustomField::where('project_id', $project->id)->where('key', $key)->exists()) {
            $key = substr($base, 0, 57).'_'.$suffix;
            $suffix++;
        }

        return $key;
    }
}
