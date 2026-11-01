<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportOrganizationChatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:all,ad_campaigns'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
