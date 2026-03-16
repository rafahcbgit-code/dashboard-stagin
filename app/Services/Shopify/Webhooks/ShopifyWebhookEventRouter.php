<?php

namespace App\Services\Shopify\Webhooks;

use App\Services\Shopify\Webhooks\Handlers\ShopifyOrderCreatedHandler;
use App\Services\Shopify\Webhooks\Handlers\ShopifyOrderFulfilledHandler;

class ShopifyWebhookEventRouter
{
    private array $map = [
        'orders/create' => ShopifyOrderCreatedHandler::class,
        'orders/fulfilled' => ShopifyOrderFulfilledHandler::class,
        'orders/updated' => ShopifyOrderFulfilledHandler::class,
    ];

    public function resolve(?string $event): ?ShopifyWebhookEventHandlerInterface
    {
        $event = is_string($event) ? $this->normalizeEvent($event) : '';

        if ($event === '') {
            return null;
        }

        $handlerClass = $this->map[$event] ?? null;
        if (!$handlerClass) {
            return null;
        }

        return app($handlerClass);
    }

    private function normalizeEvent(string $event): string
    {
        $event = preg_replace('/[\x00-\x1F\x7F]/', '', $event) ?? $event;
        $event = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $event) ?? $event;
        $event = preg_replace('/^\s+|\s+$/u', '', $event) ?? $event;
        return mb_strtolower(trim($event));
    }
}
