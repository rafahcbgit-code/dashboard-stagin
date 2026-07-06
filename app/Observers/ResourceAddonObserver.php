<?php

namespace App\Observers;

use App\Models\ResourceAddon;
use App\Services\SubscriptionAuditService;

class ResourceAddonObserver
{
    public function created(ResourceAddon $resourceAddon): void
    {
        app(SubscriptionAuditService::class)->recordResourceAddonCreated($resourceAddon);
    }

    public function deleting(ResourceAddon $resourceAddon): void
    {
        app(SubscriptionAuditService::class)->recordResourceAddonDeleted($resourceAddon);
    }
}
