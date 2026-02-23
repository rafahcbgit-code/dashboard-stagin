<?php

namespace App\Http\Controllers\Salla;

use App\Http\Controllers\Controller;
use App\Jobs\Salla\ProcessSallaWebhookDeliveryJob;
use App\Models\SallaStore;
use App\Models\SallaWebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Database\QueryException;
use Throwable;

class SallaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody = (string) $request->getContent();
        $payloadHash = hash('sha256', $rawBody);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            Log::warning('Salla webhook: invalid JSON payload', [
                'payload_hash' => $payloadHash,
            ]);

            return Response::json(['status' => 'ignored'], 200);
        }

        // Some Salla integrations wrap the actual webhook body inside a `payload` key:
        // { "payload": { "event": "...", "merchant": "...", "data": {...} } }
        // Normalize so the stored delivery payload is consistent across events.
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            $payload = $payload['payload'];
        }

        Log::info('payload recieved', [
            $payload,
        ]);

        $event = $this->extractEvent($payload);
        $merchantId = $this->extractMerchantId($payload);

        $store = null;
        $organizationId = null;
        if ($merchantId !== null) {
            $store = SallaStore::where('merchant_id', $merchantId)->first();
            $organizationId = $store?->organization_id;
        }

        try {
            $delivery = SallaWebhookDelivery::create([
                'merchant_id' => $merchantId,
                'salla_store_id' => $store?->id,
                'organization_id' => $organizationId,
                'event' => $event,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'payload_hash' => $payloadHash,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        } catch (QueryException $e) {
            // Idempotency: if we already have this payload, return 200 and do not re-dispatch.
            $delivery = SallaWebhookDelivery::where('payload_hash', $payloadHash)->first();
            if (!$delivery) {
                throw $e;
            }

            Log::info('Salla webhook: duplicate delivery ignored', [
                'payload_hash' => $payloadHash,
                'event' => $event,
                'merchant_id' => $merchantId,
                'delivery_id' => $delivery?->id,
            ]);

            return Response::json(['status' => 'ok'], 200);
        }

        if (config('salla.webhooks.sync', false)) {
            try {
                // Temporary debug mode: process synchronously (no queue).
                (new ProcessSallaWebhookDeliveryJob($delivery->id))
                    ->handle(app(\App\Services\Salla\Webhooks\SallaWebhookEventRouter::class));
            } catch (Throwable $e) {
                // Keep webhook response 200 to avoid provider retries loops.
                Log::error('Salla webhook: synchronous processing failed', [
                    'delivery_id' => $delivery->id,
                    'payload_hash' => $payloadHash,
                    'event' => $event,
                    'merchant_id' => $merchantId,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            ProcessSallaWebhookDeliveryJob::dispatch($delivery->id);
        }

        return Response::json(['status' => 'ok'], 200);
    }

    private function extractEvent(array $payload): ?string
    {
        $event = data_get($payload, 'event') ?? data_get($payload, 'payload.event');

        if (!is_scalar($event)) {
            return null;
        }

        $event = trim((string) $event);

        return $event !== '' ? $event : null;
    }

    private function extractMerchantId(array $payload): ?string
    {
        $merchantId = data_get($payload, 'merchant') ?? data_get($payload, 'payload.merchant');

        if (!is_scalar($merchantId)) {
            return null;
        }

        $merchantId = trim((string) $merchantId);

        return $merchantId !== '' ? $merchantId : null;
    }
}
