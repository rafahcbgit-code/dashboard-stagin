<?php

namespace App\Services\Salla\Webhooks;

use App\Services\Salla\Webhooks\Handlers\OrderCreatedHandler;

class SallaWebhookEventRouter
{
    /**
     * @var array<string, class-string<SallaWebhookEventHandlerInterface>>
     */
    private array $map = [
        'order.created' => OrderCreatedHandler::class,
    ];

    public function resolve(?string $event): ?SallaWebhookEventHandlerInterface
    {
        if (!$event) {
            return null;
        }

        $handlerClass = $this->map[$event] ?? null;
        if (!$handlerClass) {
            return null;
        }

        return app($handlerClass);
    }
}

