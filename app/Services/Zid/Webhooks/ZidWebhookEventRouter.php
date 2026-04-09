<?php

namespace App\Services\Zid\Webhooks;

use App\Services\Zid\Webhooks\Handlers\CustomerLoginHandler;
use App\Services\Zid\Webhooks\Handlers\OrderCreatedHandler;

class ZidWebhookEventRouter
{
    private array $map = [
        'customer.login' => CustomerLoginHandler::class,
        'order.create' => OrderCreatedHandler::class,
    ];

    public function resolve(?string $event): ?ZidWebhookEventHandlerInterface
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
        $event = trim($event);
        return mb_strtolower($event);
    }
}
