<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Models\WooCommerceWebhookDelivery;

interface WooCommerceWebhookEventHandlerInterface
{
    public function handle(WooCommerceWebhookDelivery $delivery): void;
}
