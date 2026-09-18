<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_archived' => ['boolean'],
            'default_assignee_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', app(\App\Support\Tenancy\Tenancy::class)->id()),
            ],
        ];
    }
}
