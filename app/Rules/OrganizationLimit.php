<?php

namespace App\Rules;

use App\Services\SubscriptionUsageService;
use Illuminate\Contracts\Validation\Rule;

class OrganizationLimit implements Rule
{
    public function passes($attribute, $value)
    {
        $userId = auth()->id();
        if (! $userId) {
            return false;
        }

        return (new SubscriptionUsageService())
            ->createOrganizationAllowedForUser((int) $userId, session()->get('current_organization'))
            ['allowed'];
    }

    public function message()
    {
        return __('You have reached your organizations limit. Please upgrade your account before creating another organization.');
    }
}
