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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSallaWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Orchestrates processing of a single stored Salla webhook delivery.
     *
     * Flow (high-level):
     * 1) `SallaWebhookController` stores the webhook payload into `salla_webhook_deliveries`
     *    using an idempotency hash to avoid duplicate processing.
     * 2) The controller then dispatches this job (or, in temporary debug mode, runs it synchronously).
     * 3) This job loads the delivery, resolves a handler by `event`, and delegates execution to that handler.
     *
     * Why a job?
     * - Keeps webhook HTTP response fast (avoid provider retries/timeouts).
     * - Allows retries/backoff for transient failures (e.g., WhatsApp API).
     * - Centralizes status tracking (`pending` → `processing` → `processed/failed/skipped/ignored`).
     */
    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId)
    {
    }

    /**
     * Prevent multiple workers from processing the same delivery concurrently.
     *
     * Why this matters:
     * - Providers can retry webhooks quickly.
     * - A delivery can be (re)dispatched for reliability.
     * - Without this lock, two jobs may race and send the same WhatsApp template twice.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('salla_webhook_delivery_' . $this->deliveryId))
                ->expireAfter(300),
        ];
    }

    public function handle(SallaWebhookEventRouter $router): void
    {
        // Step 1: Load the stored delivery record (contains: event, payload, merchant_id, organization_id, etc.)
        $delivery = SallaWebhookDelivery::find($this->deliveryId);
        if (!$delivery) {
            Log::warning('Salla webhook processing: delivery not found', [
                'delivery_id' => $this->deliveryId,
            ]);
            return;
        }

        // Step 2: Idempotency guard (do not re-process terminal states)
        if (in_array($delivery->status, ['processed', 'ignored', 'skipped'], true)) {
            return;
        }

        // If another job already started processing this delivery, avoid sending twice.
        // The WithoutOverlapping middleware should already prevent this, but this guard
        // adds an extra layer of safety when running synchronously.
        if ($delivery->status === 'processing') {
            return;
        }

        // Step 3: Mark as processing and track attempts (useful for monitoring/debugging)
        $delivery->update([
            'status' => 'processing',
            'attempts' => ($delivery->attempts ?? 0) + 1,
        ]);

        // Step 4: Resolve an event handler (Strategy pattern) based on the delivery's event name
        $event = (string) ($delivery->event ?? '');
        $handler = $router->resolve($event);

        if (!$handler) {
            // No handler registered for this event. We keep the delivery for observability and mark it ignored.
            Log::info('Salla webhook processing: no handler for event', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                // Helpful debug info for invisible characters / formatting issues in event names.
                'event_len' => is_string($delivery->event) ? mb_strlen($delivery->event) : null,
                'event_hex' => is_string($delivery->event) ? bin2hex($delivery->event) : null,
            ]);

            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            // Step 5: Delegate the actual work to the event handler
            $handler->handle($delivery);

            // Step 6: If the handler didn't set a terminal status, mark as processed here.
            $delivery->refresh();
            if ($delivery->status === 'processing') {
                $delivery->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            // Step 7: Persist failure state + error message for later inspection/retry.
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
                // Non-retryable failures (e.g., misconfiguration / missing mappings / invalid recipient phone).
                // Returning here prevents queue retries that would never succeed.
                return;
            }

            // Retryable failures (e.g., transient network/API errors) should bubble up so the queue can retry.
            throw $e;
        }
    }
}
