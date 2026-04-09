<?php

namespace App\Jobs\Zid;

use App\Models\ZidWebhookDelivery;
use App\Services\Zid\Webhooks\ZidWebhookEventRouter;
use App\Services\Zid\Webhooks\Exceptions\ZidWebhookNonRetryableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessZidWebhookDeliveryJob implements ShouldQueue
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
            (new WithoutOverlapping('zid_webhook_delivery_' . $this->deliveryId))
                ->expireAfter(300),
        ];
    }

    public function handle(ZidWebhookEventRouter $router): void
    {
        $delivery = ZidWebhookDelivery::find($this->deliveryId);
        if (!$delivery) {
            Log::warning('Zid webhook processing: delivery not found', [
                'delivery_id' => $this->deliveryId,
            ]);
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

        $handler = $router->resolve($delivery->event);
        if (!$handler) {
            Log::info('Zid webhook processing: no handler for event', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
            ]);

            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
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
            Log::error('Zid webhook processing failed', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                'store_id' => $delivery->store_id,
                'error' => $e->getMessage(),
            ]);

            $delivery->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof ZidWebhookNonRetryableException) {
                return;
            }

            throw $e;
        }
    }
}
