<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Services\SubscriptionAuditService;

class SubscriptionObserver
{
    public function created(Subscription $subscription): void
    {
        app(SubscriptionAuditService::class)->recordSubscriptionCreated($subscription);
    }

    public function updated(Subscription $subscription): void
    {
        app(SubscriptionAuditService::class)->recordSubscriptionUpdated($subscription);
    }
}
