<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Rules\TeamLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeam extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $digits = preg_replace('/\D+/', '', (string) $this->input('phone', ''));

        $this->merge([
            'phone' => $digits !== '' ? '+'.$digits : null,
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organizationId = session()->get('current_organization');

        if (! $organizationId || ! $this->user()) {
            return false;
        }

        return Team::where('organization_id', $organizationId)
            ->where('user_id', $this->user()->id)
            ->whereIn('role', ['owner', 'manager'])
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email', // Added the email validation rule
                Rule::unique('users', 'email')->where(function ($query) {
                    $organizationId = session()->get('current_organization');

                    $query->whereIn('id', function ($subQuery) use ($organizationId) {
                        $subQuery->select('user_id')
                            ->from('teams')
                            ->where('deleted_at', null)
                            ->where('organization_id', $organizationId);
                    });
                }),
                new TeamLimit,
            ],
            'role' => 'required|in:manager,agent',
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'create_password' => ['sometimes', 'boolean'],
            'password' => ['required_if:create_password,true', 'nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('User with this email already exists in your team.'),
            'phone.regex' => __('Enter a phone number with country code (e.g., +966, +1, +44). Minimum 8 digits required.'),
        ];
    }
}
