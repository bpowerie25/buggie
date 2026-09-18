<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'min:2', 'max:40',
                // Subdomain-safe: lowercase alphanumeric and single inner hyphens.
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(Workspace::RESERVED_SLUGS),
                Rule::unique('workspaces', 'slug')->withoutTrashed(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'slug.not_in' => 'That address is reserved. Please choose another.',
            'slug.unique' => 'That address is already taken.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => strtolower(trim($this->string('slug')->toString()))]);
        }
    }
}
