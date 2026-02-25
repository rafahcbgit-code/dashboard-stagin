<?php

namespace App\Services\Salla\Webhooks;

use App\Services\Salla\Webhooks\Handlers\OrderCreatedHandler;
use App\Services\Salla\Webhooks\Handlers\OrderStatusUpdatedHandler;
use App\Services\Salla\Webhooks\Handlers\AbandonedCartHandler;
use App\Services\Salla\Webhooks\Handlers\CustomerLoginHandler;
use App\Services\Salla\Webhooks\Handlers\AppInstalledHandler;

class SallaWebhookEventRouter
{
    /**
     * Event → Handler mapping (Strategy pattern).
     *
     * Add new events by:
     * 1) Creating a new handler that implements `SallaWebhookEventHandlerInterface`
     * 2) Registering it in this map (e.g. 'order.updated' => OrderUpdatedHandler::class)
     *
     * Notes:
     * - This keeps the job/controller logic generic and extensible.
     * - Unknown events are treated as "ignored" by the job.
     *
     * @var array<string, class-string<SallaWebhookEventHandlerInterface>>
     */
    private array $map = [
        'order.created' => OrderCreatedHandler::class,
        'order.status.updated' => OrderStatusUpdatedHandler::class,
        'abandoned.cart' => AbandonedCartHandler::class,
        'customer.login' => CustomerLoginHandler::class,
        'app.installed' => AppInstalledHandler::class,
        'app.uninstalled' => \App\Services\Salla\Webhooks\Handlers\AppUninstalledHandler::class,
    ];

    public function resolve(?string $event): ?SallaWebhookEventHandlerInterface
    {
        $event = is_string($event) ? $this->normalizeEvent($event) : '';

        // Empty/unknown events cannot be routed.
        if ($event === '') {
            return null;
        }

        $handlerClass = $this->map[$event] ?? null;
        if (!$handlerClass) {
            return null;
        }

        // Let the container build the handler (so dependencies can be injected).
        return app($handlerClass);
    }

    private function normalizeEvent(string $event): string
    {
        // Remove ASCII control characters (sometimes appear when providers include CR/LF, etc.).
        $event = preg_replace('/[\x00-\x1F\x7F]/', '', $event) ?? $event;

        // Remove common invisible Unicode characters (BOM / zero-width space).
        $event = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $event) ?? $event;

        // Trim Unicode whitespace from both ends.
        $event = preg_replace('/^\s+|\s+$/u', '', $event) ?? $event;

        $event = trim($event);

        // Normalize to lower-case for consistent routing.
        return mb_strtolower($event);
    }
}
