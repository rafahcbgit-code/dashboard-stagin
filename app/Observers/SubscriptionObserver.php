<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Services\SubscriptionAuditService;

class SubscriptionObserver
{
    public function updating(Subscription $subscription): void
    {
        if (array_key_exists('period_version', $subscription->getAttributes())
            && $subscription->isDirty(['plan_id', 'status', 'start_date', 'valid_until'])
            && ! $subscription->isDirty('period_version')) {
            $subscription->period_version = (int) $subscription->getRawOriginal('period_version') + 1;
        }
    }

    public function created(Subscription $subscription): void
    {
        app(SubscriptionAuditService::class)->recordSubscriptionCreated($subscription);
    }

    public function updated(Subscription $subscription): void
    {
        app(SubscriptionAuditService::class)->recordSubscriptionUpdated($subscription);
    }
}
