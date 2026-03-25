<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\ShopifyWebhookDelivery;

interface ShopifyWebhookEventHandlerInterface
{
    public function handle(ShopifyWebhookDelivery $delivery): void;
}
