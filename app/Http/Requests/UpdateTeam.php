<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeam extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $digits = preg_replace('/\D+/', '', (string) $this->input('phone', ''));

        $this->merge([
            'phone' => $digits !== '' ? '+'.$digits : null,
        ]);
    }

    public function authorize(): bool
    {
        $organizationId = session()->get('current_organization');

        if (! $organizationId || ! $this->user()) {
            return false;
        }

        $canManageTeam = Team::where('organization_id', $organizationId)
            ->where('user_id', $this->user()->id)
            ->whereIn('role', ['owner', 'manager'])
            ->whereNull('deleted_at')
            ->exists();

        $targetIsEditable = Team::where('uuid', $this->route('uuid'))
            ->where('organization_id', $organizationId)
            ->where('role', '!=', 'owner')
            ->whereNull('deleted_at')
            ->exists();

        return $canManageTeam && $targetIsEditable;
    }

    public function rules(): array
    {
        return [
            'role' => ['nullable', 'in:manager,agent'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a phone number with country code (e.g., +966, +1, +44). Minimum 8 digits required.'),
        ];
    }
}
