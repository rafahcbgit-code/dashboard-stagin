<?php

namespace App\Http\Controllers\Zid;

use App\Http\Controllers\Controller;
use App\Jobs\Zid\ProcessZidWebhookDeliveryJob;
use App\Models\IntegrationConnection;
use App\Models\ZidWebhookDelivery;
use App\Models\ZidWebhookSubscription;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class ZidWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody = (string) $request->getContent();
        $payloadHash = hash('sha256', $rawBody);
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            Log::channel('zid')->warning('Zid webhook received invalid JSON', [
                'payload_hash' => $payloadHash,
                'headers' => $request->headers->all(),
            ]);

            return Response::json(['status' => 'ignored'], 200);
        }
        
        $event = $request->header('webhook-event') ?? data_get($payload, 'event');

        [$storeId, $connection] = $this->resolveConnection($payload, $event);

        Log::channel('zid')->info('Zid webhook received', [
            'event' => $event,
            'webhook_id' => $payload['webhook_id'] ?? null,
            'store_id' => $storeId,
        ]);

        try {
            $delivery = ZidWebhookDelivery::create([
                'store_id' => $storeId,
                'integration_connection_id' => $connection?->id,
                'organization_id' => $connection?->organization_id,
                'event' => is_scalar($event) ? trim((string) $event) : null,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'payload_hash' => $payloadHash,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        } catch (QueryException $exception) {
            Log::channel('zid')->info('Zid webhook delivery duplicate detected', [
                'payload_hash' => $payloadHash,
                'store_id' => $storeId,
                'event' => $event,
            ]);

            return Response::json(['status' => 'ok'], 200);
        }

        ProcessZidWebhookDeliveryJob::dispatch($delivery->id);

        $subscription = ZidWebhookSubscription::where('store_id', $storeId)
            ->where('event_name', is_scalar($event) ? trim((string) $event) : '')
            ->first();

        if ($subscription) {
            $subscription->touch();
        } else {
            Log::channel('zid')->warning('Zid webhook received for unknown subscription', $payload);
        }

        return Response::json(['status' => 'ok']);
    }

    private function resolveConnection(array $payload, ?string $event): array
    {
        $storeId = data_get($payload, 'store_id')
            ?? data_get($payload, 'store.id')
            ?? data_get($payload, 'data.store_id');

        $storeId = is_scalar($storeId) ? trim((string) $storeId) : '';

        $connection = null;
        if ($storeId !== '') {
            $query = IntegrationConnection::where('platform', config('zid.platform'));
            if ($event === 'abandoned_cart.created') {
                $connection = (clone $query)->where('store_uuid', $storeId)->first();
            }

            if (! $connection) {
                $connection = $query->where('store_id', $storeId)->first();
            }
        }

        return [$storeId, $connection];
    }
}
