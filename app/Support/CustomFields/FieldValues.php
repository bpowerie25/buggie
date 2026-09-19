<?php

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reading and writing custom field values on an issue.
 *
 * One class rather than logic in each controller, because there are four ways in —
 * the issue form, the API, triage promoting a report, and an import — and the last
 * time a rule lived in a controller the API disagreed with the UI about who could
 * see what.
 */
class FieldValues
{
    /**
     * Validate a submitted map of key => value against a project's fields.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<int, string|null>  field id => value ready to store
     *
     * @throws ValidationException
     */
    public function validate(
        Project $project,
        array $submitted,
        bool $enforceRequired = true,
        bool $partial = false,
    ): array {
        $fields = $this->definitions($project);
        $resolved = [];

        foreach ($fields as $field) {
            /*
             * In partial mode a field nobody mentioned is left exactly as it was.
             *
             * The issue sidebar patches one field at a time, and without this every
             * other field on the issue arrived as "absent", resolved to null and was
             * deleted — editing a browser version would have silently wiped the
             * client reference beside it.
             *
             * array_key_exists rather than isset, because an explicit null is how the
             * form says "clear this", and isset() cannot tell the two apart.
             */
            if ($partial && ! array_key_exists($field->key, $submitted)) {
                continue;
            }

            $given = $submitted[$field->key] ?? null;

            // Absent and empty are the same thing: a cleared text box arrives as "".
            $isBlank = $given === null || $given === '' || $given === [];

            if ($isBlank) {
                if ($field->required && $enforceRequired) {
                    throw ValidationException::withMessages([
                        "custom_fields.{$field->key}" => "{$field->name} is required.",
                    ]);
                }

                $resolved[$field->id] = null;

                continue;
            }

            $resolved[$field->id] = $this->cast($field, $given);
        }

        // Keys that match no field are dropped rather than rejected. An import or an
        // older API client sending a field somebody has since deleted should not have
        // its whole request fail over it.

        return $resolved;
    }

    /**
     * Coerce and validate one value, or fail.
     *
     * @throws ValidationException
     */
    private function cast(CustomField $field, mixed $given): string
    {
        if (is_array($given)) {
            throw ValidationException::withMessages([
                "custom_fields.{$field->key}" => "{$field->name} does not take a list.",
            ]);
        }

        $value = is_bool($given) ? ($given ? '1' : '0') : (string) $given;

        $validator = Validator::make(
            ['value' => $value],
            ['value' => $field->type->rules()],
            [],
            ['value' => $field->name],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                "custom_fields.{$field->key}" => $validator->errors()->first('value'),
            ]);
        }

        // A select must be one of its own options. Without this the field is a text
        // box wearing a dropdown, and the export grows a column of one-off spellings.
        if ($field->type === CustomFieldType::Select
            && ! in_array($value, (array) ($field->options ?? []), true)) {
            throw ValidationException::withMessages([
                "custom_fields.{$field->key}" => "{$field->name} must be one of the choices offered.",
            ]);
        }

        if ($field->type === CustomFieldType::Checkbox) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($field->type === CustomFieldType::Date) {
            return date('Y-m-d', strtotime($value));
        }

        return $value;
    }

    /**
     * Write the values, replacing what was there.
     *
     * @param  array<int, string|null>  $values  field id => value
     */
    public function store(Issue $issue, array $values): void
    {
        foreach ($values as $fieldId => $value) {
            if ($value === null) {
                CustomFieldValue::where('issue_id', $issue->id)
                    ->where('custom_field_id', $fieldId)
                    ->delete();

                continue;
            }

            CustomFieldValue::updateOrCreate(
                ['issue_id' => $issue->id, 'custom_field_id' => $fieldId],
                ['value' => $value],
            );
        }
    }

    /** @return Collection<int, CustomField> */
    public function definitions(Project $project, bool $clientOnly = false): Collection
    {
        return CustomField::where('project_id', $project->id)
            ->when($clientOnly, fn ($q) => $q->visibleToClient())
            ->inOrder()
            ->get();
    }

    /**
     * An issue's values, shaped for the front end.
     *
     * `$clientOnly` is not a convenience: a field marked internal must not reach a
     * client's browser at all, not merely go unrendered. Filtering in the template
     * would put "Internal estimate: 3 days" in the page source of a client's issue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forIssue(Issue $issue, bool $clientOnly = false): array
    {
        $fields = $this->definitions($issue->project, $clientOnly);

        if ($fields->isEmpty()) {
            return [];
        }

        $values = CustomFieldValue::where('issue_id', $issue->id)
            ->whereIn('custom_field_id', $fields->pluck('id'))
            ->pluck('value', 'custom_field_id');

        return $fields->map(fn (CustomField $field) => [
            'id' => $field->id,
            'key' => $field->key,
            'name' => $field->name,
            'type' => $field->type->value,
            'options' => $field->options ?? [],
            'required' => $field->required,
            'visible_to_client' => $field->visible_to_client,
            'value' => $values[$field->id] ?? null,
        ])->values()->all();
    }
}
