<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Services\WooCommerce\Webhooks\Handlers\WooCommerceOrderCreatedHandler;
use App\Services\WooCommerce\Webhooks\Handlers\WooCommerceOrderUpdatedHandler;
use App\Services\WooCommerce\Webhooks\Handlers\WooCommerceAddToCartHandler;

class WooCommerceWebhookEventRouter
{
    public function __construct(
        private readonly WooCommerceOrderCreatedHandler $orderCreatedHandler,
        private readonly WooCommerceOrderUpdatedHandler $orderUpdatedHandler,
        private readonly WooCommerceAddToCartHandler $addToCartHandler
    ) {
    }

    public function resolve(string $topic): ?WooCommerceWebhookEventHandlerInterface
    {
        return match ($topic) {
            'order.created' => $this->orderCreatedHandler,
            'order.updated' => $this->orderUpdatedHandler,
            'action.woocommerce_order_status_changed' => $this->orderUpdatedHandler,
            'action.woocommerce_add_to_cart' => $this->addToCartHandler,
            default => null,
        };
    }
}
