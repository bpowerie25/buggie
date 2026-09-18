<?php

namespace App\Http\Requests;

use App\Enums\StatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreStatusRequest extends FormRequest
{
    public function rules(): array
    {
        $project = $this->route('project');
        $status = $this->route('status');

        return [
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('statuses', 'name')
                    ->where('project_id', $project?->id ?? $status?->project_id)
                    ->ignore($status),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            // Only on creation. The category is the fixed spine behind the name, and
            // changing it later rewrites the meaning of history: issues that closed
            // under it would silently reopen, or the reverse.
            'category' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                new Enum(StatusCategory::class),
            ],

            'is_default' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This project already has a status with that name.',
            'category.prohibited' => 'A status keeps the category it was created with. '
                .'Add a new status and move issues across instead.',
        ];
    }
}
