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
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key must be uppercase letters and digits, starting with a letter.',
            'key.unique' => 'Another project in this workspace already uses that key.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('key')) {
            $this->merge(['key' => strtoupper(trim($this->string('key')->toString()))]);
        }
    }
}
