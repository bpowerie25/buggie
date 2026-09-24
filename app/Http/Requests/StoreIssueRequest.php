<?php

namespace App\Http\Requests;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreIssueRequest extends FormRequest
{
    public function rules(): array
    {
        $workspaceId = app(Tenancy::class)->id();

        return [
            'project_id' => [
                'required',
                // Scoped by hand: validation rules do not see the workspace scope.
                Rule::exists('projects', 'id')->where('workspace_id', $workspaceId),
            ],
            'title' => ['required', 'string', 'max:255'],
            // A key: created as a subtask of that issue. Resolved and checked in the
            // controller, against the workspace scope.
            'parent' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'array'],
            'type' => ['required', new Enum(IssueType::class)],
            'priority' => ['required', Rule::in(array_column(IssuePriority::cases(), 'value'))],
            'status_id' => [
                'nullable',
                Rule::exists('statuses', 'id')->where('workspace_id', $workspaceId),
            ],
            'assignee_id' => [
                'nullable',
                // Staff only: see Assignable.
                \App\Support\Issues\Assignable::rule($workspaceId),
            ],
            'visibility' => ['required', new Enum(IssueVisibility::class)],
            'labels' => ['array'],
            'labels.*' => [Rule::exists('labels', 'id')->where('workspace_id', $workspaceId)],

            // Shape only. What each value has to be depends on the project's field
            // definitions, which this request has no business knowing — CreateIssue
            // validates them against the project so the API and the form cannot
            // disagree about it.
            'custom_fields' => ['array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'type' => IssueType::Bug->value,
            'priority' => IssuePriority::None->value,
            'visibility' => IssueVisibility::Internal->value,
            'labels' => [],
        ]);
    }

    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'Only somebody on the team can be assigned an issue. To ask a client something, use Reply & await client.',
        ];
    }
}
