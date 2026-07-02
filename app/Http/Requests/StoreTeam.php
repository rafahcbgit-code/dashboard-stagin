<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\TeamLimit;

class StoreTeam extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organizationId = session()->get('current_organization');

        if (!$organizationId || !$this->user()) {
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
                new TeamLimit
            ],
            'role' => 'required'
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('User with this email already exists in your team.'),
        ];
    }
}
