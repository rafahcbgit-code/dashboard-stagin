<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManageSubscriptionPeriod extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    public function rules(): array
    {
        return [
            'valid_until' => ['required', 'date'],
            'subscription_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
            'operation_key' => ['required', 'uuid'],
        ];
    }
}
