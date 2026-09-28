<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Rules\NotRoleUser;
use App\Rules\NotUniqueEmail;
use App\Rules\UniqueEmail;
use App\Services\SubscriptionUsageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganization extends FormRequest
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
            'name' => 'required',
            'plan' => 'required',
            'valid_until' => $this->isMethod('post') ? 'nullable|date|after_or_equal:now' : 'nullable|date',
            'subscription_status' => 'nullable|in:trial,active,suspended',
            'billing_organization_id' => 'nullable|integer|exists:organizations,id',
            'campaign_phone_mps' => 'nullable|integer|min:1|max:'.((int) config('campaigns.throttle.max_phone_mps', 80)),
        ];

        if ($this->isMethod('post')) {
            if ($this->input('create_user') == 1) {
                $rules += [
                    'email' => ['required', 'email', new UniqueEmail],
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'password' => 'required|confirmed',
                ];
            } else {
                $rules['email'] = ['required', 'email', new NotUniqueEmail];
            }
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $billingOrganizationId = $this->input('billing_organization_id');
            if (! $billingOrganizationId) {
                return;
            }

            $currentOrganizationId = $this->currentOrganizationId();
            if ($currentOrganizationId && (int) $billingOrganizationId === $currentOrganizationId) {
                $validator->errors()->add('billing_organization_id', __('An organization cannot use itself as a resource account.'));
                return;
            }

            $billingOrganization = Organization::find($billingOrganizationId);
            if ($billingOrganization?->billing_organization_id) {
                $validator->errors()->add('billing_organization_id', __('Please select a standalone organization as the resource account.'));
            }

            if (! (new SubscriptionUsageService())->resourceSharingEnabledForOrganization((int) $billingOrganizationId)) {
                $validator->errors()->add('billing_organization_id', __('The selected organization plan does not allow resource sharing.'));
            }

            if ($currentOrganizationId && Organization::where('billing_organization_id', $currentOrganizationId)->exists()) {
                $validator->errors()->add('billing_organization_id', __('An organization that already has shared-resource organizations cannot be moved under another resource account.'));
            }

            $currentResourceAccountId = $currentOrganizationId
                ? (new SubscriptionUsageService())->resourceAccountId($currentOrganizationId)
                : null;

            if ((int) $billingOrganizationId !== (int) $currentResourceAccountId) {
                $check = (new SubscriptionUsageService())
                    ->canUse((int) $billingOrganizationId, SubscriptionUsageService::ORGANIZATIONS);

                if (! $check['allowed']) {
                    $validator->errors()->add('billing_organization_id', $check['message']);
                }
            }
        });
    }

    private function currentOrganizationId(): ?int
    {
        $uuid = $this->route('organization');
        if (! $uuid) {
            return null;
        }

        return Organization::where('uuid', $uuid)->value('id');
    }
}
