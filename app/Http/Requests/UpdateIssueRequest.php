<?php

namespace App\Http\Requests;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Partial update: inline edits from the issue list send one field at a time, so every
 * rule is conditional on the field being present.
 */
class UpdateIssueRequest extends FormRequest
{
    public function rules(): array
    {
        $workspaceId = app(Tenancy::class)->id();

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'array'],
            'type' => ['sometimes', new Enum(IssueType::class)],
            'priority' => ['sometimes', Rule::in(array_column(IssuePriority::cases(), 'value'))],
            'visibility' => ['sometimes', new Enum(IssueVisibility::class)],
            'due_on' => ['sometimes', 'nullable', 'date'],
            // Scoped to the workspace, so a version id from another tenant is not a
            // version at all. Whether it belongs to the issue's project is checked
            // in the action, which knows the issue.
            'version_id' => [
                'sometimes', 'nullable',
                Rule::exists('versions', 'id')->where('workspace_id', $workspaceId),
            ],
            'status_id' => [
                'sometimes', 'required',
                Rule::exists('statuses', 'id')->where('workspace_id', $workspaceId),
            ],
            'assignee_id' => [
                'sometimes', 'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId),
            ],
            'labels' => ['sometimes', 'array'],
            'labels.*' => [Rule::exists('labels', 'id')->where('workspace_id', $workspaceId)],

            // "sometimes", so that a bulk status change does not arrive looking like
            // a request to clear every custom field on every issue it touches.
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
