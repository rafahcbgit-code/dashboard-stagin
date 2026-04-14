<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Services\WooCommerce\Webhooks\Handlers\WooCommerceOrderCreatedHandler;
use App\Services\WooCommerce\Webhooks\Handlers\WooCommerceOrderUpdatedHandler;

class WooCommerceWebhookEventRouter
{
    public function __construct(
        private readonly WooCommerceOrderCreatedHandler $orderCreatedHandler,
        private readonly WooCommerceOrderUpdatedHandler $orderUpdatedHandler
    ) {
    }

    public function resolve(string $topic): ?WooCommerceWebhookEventHandlerInterface
    {
        return match ($topic) {
            'order.created' => $this->orderCreatedHandler,
            'order.updated' => $this->orderUpdatedHandler,
            default => null,
        };
    }
}
