<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date', 'before_or_equal:date_to'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'tag_name' => ['nullable', 'string', 'max:128'],
            'group_name' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.required' => 'Date from is required.',
            'date_to.required' => 'Date to is required.',
        ];
    }
}
