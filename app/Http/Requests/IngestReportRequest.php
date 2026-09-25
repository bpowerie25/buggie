<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'console.*' => ['array'],
            'console.*.level' => ['nullable', 'string', 'max:10'],
            'console.*.message' => ['nullable', 'string', 'max:2000'],
            'network' => ['nullable', 'array', 'max:30'],
            'network.*' => ['array'],
            'network.*.method' => ['nullable', 'string', 'max:10'],
            'network.*.url' => ['nullable', 'string', 'max:2048'],

            'screenshot' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The whole report, capped before anything else is looked at.
     *
     * The endpoint is open to the internet, and the per-field rules only bound the
     * fields they name: anything else under environment or error, and every console
     * or network entry, was bounded only by the web server. A real report is a few
     * kilobytes; a quarter of a megabyte is generous and still stops somebody
     * filling a customer's database — or their monthly allowance — with padding.
     */
    protected function prepareForValidation(): void
    {
        if (strlen((string) $this->getContent()) > self::MAX_BYTES) {
            throw new HttpResponseException(
                response()->json(['message' => 'That report is too large.'], 413),
            );
        }
    }

    public const MAX_BYTES = 256 * 1024;

    public function messages(): array
    {
        return ['title.required' => 'Tell us what went wrong.'];
    }
}
