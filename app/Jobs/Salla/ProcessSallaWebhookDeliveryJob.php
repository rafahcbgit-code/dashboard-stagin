<?php

namespace App\Jobs\Salla;

use App\Models\SallaWebhookDelivery;
use App\Services\Salla\Webhooks\SallaWebhookEventRouter;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSallaWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(SallaWebhookEventRouter $router): void
    {
        $delivery = SallaWebhookDelivery::find($this->deliveryId);
        if (!$delivery) {
            Log::warning('Salla webhook processing: delivery not found', [
                'delivery_id' => $this->deliveryId,
            ]);
            return;
        }

        if (in_array($delivery->status, ['processed', 'ignored', 'skipped'], true)) {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => ($delivery->attempts ?? 0) + 1,
        ]);

        $event = (string) ($delivery->event ?? '');
        $handler = $router->resolve($event);

        if (!$handler) {
            Log::info('Salla webhook processing: no handler for event', [
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
            Log::error('Salla webhook processing failed', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                'merchant_id' => $delivery->merchant_id,
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
