<?php

namespace Modules\Webhook\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebhook extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'url' => 'required|url|unique:webhooks,url',
            'events' => 'required|array',
            'events.*' => 'string'
        ];

        return $rules;
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
