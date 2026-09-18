<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The widget payload. Every field is attacker-controlled: the public key sits in the
 * page source of the customer's app, so anyone can post here.
 *
 * Sizes are capped hard. Nothing in here is trusted to be the shape it claims.
 */
class IngestReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],

            'reporter.name' => ['nullable', 'string', 'max:120'],
            'reporter.email' => ['nullable', 'email', 'max:255'],
            'reporter.ref' => ['nullable', 'string', 'max:120'],

            'environment' => ['nullable', 'array'],
            'environment.url' => ['nullable', 'string', 'max:2048'],
            'environment.user_agent' => ['nullable', 'string', 'max:512'],
            'environment.release' => ['nullable', 'string', 'max:120'],

            'error' => ['nullable', 'array'],
            'error.message' => ['nullable', 'string', 'max:2000'],
            'error.stack' => ['nullable', 'string', 'max:8000'],

            'console' => ['nullable', 'array', 'max:50'],
            'network' => ['nullable', 'array', 'max:30'],

            'screenshot' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Tell us what went wrong.'];
    }
}
