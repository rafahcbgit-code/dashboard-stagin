<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileAddress extends FormRequest
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
            'organization_name' => 'required',
            'address' => 'required',
            'country' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'city' => 'required',
            'enable_campaign_resend' => 'boolean',
            'move_failed_contacts_to_group' => 'boolean',
            'resend_intervals' => 'nullable|array',
            'resend_intervals.*' => 'integer|min:1|max:24',
            'failed_campaign_group' => 'required_if:move_failed_contacts_to_group,true|nullable|exists:contact_groups,uuid',
            'timezone' => 'nullable|timezone',
            'enable_working_hours' => 'boolean',
            'working_hours' => 'required_if:enable_working_hours,true|array',
            'working_hours.*.day' => 'required|distinct|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'working_hours.*.active' => 'boolean',
            'working_hours.*.from' => 'nullable|date_format:H:i',
            'working_hours.*.to' => 'nullable|date_format:H:i',

        ];

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->boolean('enable_working_hours')) return;
            $days = $this->input('working_hours', []);
            if (!is_array($days)) return;
            foreach ($days as $index => $day) {
                if (!is_array($day)) continue;
                if (!empty($day['active'])) {
                    foreach (['from', 'to'] as $field) {
                        if (empty($day[$field])) $validator->errors()->add("working_hours.$index.$field", 'A time is required for an active day.');
                    }
                }
            }
        });
    }
}
