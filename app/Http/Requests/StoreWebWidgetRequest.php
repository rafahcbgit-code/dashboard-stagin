<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class StoreWebWidgetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'whatsapp_phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+[1-9]\d{7,14}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        $phone = new PhoneNumber((string) $value);

                        if (! $phone->isValid()) {
                            $fail(__('The :attribute field must be a valid phone number.', ['attribute' => $attribute]));
                        }
                    } catch (\Throwable $e) {
                        $fail(__('The :attribute field must be a valid phone number.', ['attribute' => $attribute]));
                    }
                },
            ],
            'header_text' => ['nullable', 'string', 'max:255'],
            'header_subtext' => ['nullable', 'string', 'max:255'],
            'widget_text' => ['nullable', 'string', 'max:2000'],
            'button_text' => ['nullable', 'string', 'max:255'],
            'widget_type' => ['required', Rule::in(['start_button', 'input_field'])],
            'input_placeholder' => ['nullable', 'string', 'max:255', 'required_if:widget_type,input_field'],
            'button_color' => ['required', 'string', 'regex:/^#(?:[A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'header_color' => ['required', 'string', 'regex:/^#(?:[A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_phone.regex' => __('WhatsApp phone must be in international format, for example: +15551234567.'),
            'input_placeholder.required_if' => __('Input placeholder is required when widget type is input field.'),
            'button_color.regex' => __('Button color must be a valid HEX value (e.g. #25D366).'),
            'header_color.regex' => __('Header color must be a valid HEX value (e.g. #128C7E).'),
        ];
    }
}
