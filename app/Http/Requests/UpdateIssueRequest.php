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
        ];
    }
}
