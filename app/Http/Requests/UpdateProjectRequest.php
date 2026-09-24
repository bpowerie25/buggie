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
            // Seeds the widget origin allowlist; see Project::defaultWidgetOrigins().
            'site_url' => ['nullable', 'url', 'max:255'],
            'is_archived' => ['boolean'],
            // Waiting on a client: both off unless a day count is given. Closing
            // before the reminder would mean the reminder never goes.
            'awaiting_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Whether clients who hold the project may see its timeline.
            // off, issues or phases; true and false still mean issues and off.
            'client_timeline' => ['sometimes', \Illuminate\Validation\Rule::in([true, false, 1, 0, '1', '0', 'off', 'issues', 'phases'])],
            'awaiting_close_days' => ['nullable', 'integer', 'min:1', 'max:365', function ($attribute, $value, $fail) {
                $reminder = $this->input('awaiting_reminder_days');

                if ($value !== null && is_numeric($reminder) && (int) $value <= (int) $reminder) {
                    $fail('Close after the reminder, or the reminder would never be sent.');
                }
            }],
            // Staff only. A client here would be handed every new issue silently,
            // with no activity entry to say so.
            'default_assignee_id' => [
                'nullable',
                \App\Support\Issues\Assignable::rule(app(\App\Support\Tenancy\Tenancy::class)->id()),
            ],
        ];
    }
}
