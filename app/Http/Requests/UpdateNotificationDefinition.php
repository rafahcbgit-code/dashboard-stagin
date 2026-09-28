<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationDefinition extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The admin route has the notifications.edit permission.
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'delivery_mode' => ['required', 'in:dashboard,email,dashboard_email'],
            'dashboard_template' => ['required', 'string', 'max:20000'],
            'email_subject' => ['required', 'string', 'max:20000'],
            'email_body' => ['required', 'string', 'max:20000'],
            'sender_email' => ['required', 'email', 'max:191'],
            'webhook_url' => ['nullable', 'string', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'max:128'],
        ];
    }
}
