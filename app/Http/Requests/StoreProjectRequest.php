<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'key' => [
                'nullable', 'string', 'min:2', 'max:6', 'regex:/^[A-Z][A-Z0-9]*$/',
                // The workspace scope is not applied to the unique rule, so scope it here.
                Rule::unique('projects', 'key')
                    ->where('workspace_id', app(\App\Support\Tenancy\Tenancy::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            // Seeds the widget origin allowlist; see Project::defaultWidgetOrigins().
            'site_url' => ['nullable', 'url', 'max:255'],

            // A key that is not a template is refused rather than ignored: falling
            // back to the defaults would silently create a project nobody asked for.
            'template' => [
                'nullable', 'string',
                Rule::in(app(\App\Support\Templates\ProjectTemplates::class)->keys()),
            ],

            /*
             * Copying another project's setup.
             *
             * Validation rules run raw SQL and never see the workspace global scope,
             * so the scope is applied by hand here. An id from another workspace is
             * not a 404 waiting to happen — it is both a leak (the names and field
             * keys of another customer's project) and a corruption (their rows read
             * while ours are written), so it is refused outright.
             */
            'source_project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')
                    ->where('workspace_id', app(\App\Support\Tenancy\Tenancy::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key must be uppercase letters and digits, starting with a letter.',
            'key.unique' => 'Another project in this workspace already uses that key.',
            'template.in' => 'That is not one of the project templates.',
            'source_project_id.exists' => 'There is no such project in this workspace.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('key')) {
            $this->merge(['key' => strtoupper(trim($this->string('key')->toString()))]);
        }
    }
}
