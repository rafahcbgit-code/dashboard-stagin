<?php

namespace Modules\Webhook\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhook extends FormRequest
{
    public function rules()
    {
        return [
            'url' => 'required|url',
            'events' => 'required|array',
            'events.*' => 'string', // Assuming events are strings
        ];
    }

    public function authorize()
    {
        return true; // Adjust as necessary for your authorization logic
    }
    public function messages(): array
    {
        return [
            'url.required' => __('This field is required.'),
            'url.url' => __('Please enter a valid URL.'),
            'url.unique' => __('This webhook URL already exists.'),
            'events.required' => __('Please select at least one event.'),
            'events.array' => __('Please select at least one event.'),
            'events.*.string' => __('Invalid event.'),
        ];
    }
}
