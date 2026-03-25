<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifyWebhookDelivery;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use App\Services\Shopify\Webhooks\ShopifyWebhookEventRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleShopifyWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId)
    {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('shopify_webhook_delivery_' . $this->deliveryId))
                ->expireAfter(300),
        ];
    }

    public function handle(ShopifyWebhookEventRouter $router): void
    {
        $delivery = ShopifyWebhookDelivery::find($this->deliveryId);
        if (!$delivery) {
            return;
        }

        if (in_array($delivery->status, ['processed', 'ignored', 'skipped'], true)) {
            return;
        }

        if ($delivery->status === 'processing') {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => ($delivery->attempts ?? 0) + 1,
        ]);

        $event = (string) ($delivery->topic ?? '');
        $handler = $router->resolve($event);

        if (!$handler) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'event_not_supported',
            ]);
            return;
        }

        try {
            $handler->handle($delivery);
            $delivery->refresh();
            if ($delivery->status === 'processing') {
                $delivery->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Shopify webhook processing failed', [
                'delivery_id' => $delivery->id,
                'shopify_store_id' => $delivery->shopify_store_id,
                'organization_id' => $delivery->organization_id,
                'error' => $e->getMessage(),
            ]);

            $delivery->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof SallaWebhookNonRetryableException) {
                return;
            }

            throw $e;
        }
    }
}
