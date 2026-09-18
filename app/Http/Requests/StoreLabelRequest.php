<?php

namespace App\Http\Requests;

use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('labels', 'name')
                    ->where('workspace_id', app(Tenancy::class)->id())
                    ->ignore($this->route('label')),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['color' => '#64748b']);
    }
}
