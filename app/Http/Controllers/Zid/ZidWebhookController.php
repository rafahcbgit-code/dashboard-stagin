<?php

namespace App\Http\Controllers\Zid;

use App\Http\Controllers\Controller;
use App\Models\ZidWebhookSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZidWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        Log::info('Zid webhook received', [
            'event' => $payload['event'] ?? null,
            'webhook_id' => $payload['webhook_id'] ?? null,
            'store_id' => data_get($payload, 'store.id'),
        ]);

        $subscription = ZidWebhookSubscription::where('store_id', data_get($payload, 'store.id'))
            ->where('event_name', $payload['event'] ?? '')
            ->first();

        if ($subscription) {
            $subscription->touch();
        } else {
            Log::warning('Zid webhook received for unknown subscription', $payload);
        }

        return response()->json(['status' => 'ok']);
    }
}
