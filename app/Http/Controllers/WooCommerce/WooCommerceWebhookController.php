<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Models\WooCommerceWebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $webhookId = $request->header('x-wc-webhook-id');
        $topic = $request->header('x-wc-webhook-topic');
        $signature = $request->header('x-wc-webhook-signature');

        Log::info('WooCommerce webhook raw request', [
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'headers' => $request->headers->all(),
            'body' => $rawBody,
        ]);

        if (!$webhookId) {
            Log::warning('WooCommerce webhook missing webhook id', [
                'topic' => $topic,
                'headers' => $request->headers->all(),
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $subscription = WooCommerceWebhookSubscription::where('webhook_id', $webhookId)->first();

        if (!$subscription || !$subscription->is_active) {
            Log::warning('WooCommerce webhook ignored (subscription not found/inactive)', [
                'webhook_id' => $webhookId,
                'topic' => $topic,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $integration = $subscription->integration;
        if (!$integration || !$integration->webhook_secret) {
            Log::warning('WooCommerce webhook ignored (missing integration secret)', [
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'subscription_id' => $subscription->id,
                'integration->webhook_secret' => !$integration->webhook_secret,
                'integration' => !$integration
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        /*$expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $integration->webhook_secret, true));
        if (!$signature || !hash_equals($expectedSignature, $signature)) {
            Log::warning('WooCommerce webhook signature mismatch', [
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'expected' => Str::limit($expectedSignature, 16, '...'),
                'received' => Str::limit((string) $signature, 16, '...'),
            ]);

            return response()->json(['status' => 'unauthorized'], 401);
        }*/

        if ($topic && $subscription->topic && $subscription->topic !== $topic) {
            Log::warning('WooCommerce webhook topic mismatch', [
                'webhook_id' => $webhookId,
                'received_topic' => $topic,
                'expected_topic' => $subscription->topic,
            ]);
        }

        Log::info('WooCommerce webhook received', [
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
        ]);

        return response()->json(['status' => 'ok'], 200);
    }
}
