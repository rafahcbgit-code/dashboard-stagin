<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Jobs\WooCommerce\ProcessWooCommerceWebhookDeliveryJob;
use App\Models\WooCommerceWebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $webhookId = $request->header('x-wc-webhook-id');
        $topic = $request->header('x-wc-webhook-topic');
        $signature = $request->header('x-wc-webhook-signature');
        $source = $request->header('x-wc-webhook-source');
        $sourceHost = $this->normalizeHost($source);

        Log::channel('woocommerce')->info('WooCommerce webhook raw request', [
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'source' => $source,
            'headers' => $request->headers->all(),
            'body' => $rawBody,
        ]);

        if (!$webhookId) {
            Log::channel('woocommerce')->warning('WooCommerce webhook missing webhook id', [
                'topic' => $topic,
                'headers' => $request->headers->all(),
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $subscriptions = WooCommerceWebhookSubscription::with('integration')
            ->where('webhook_id', $webhookId)
            ->where('is_active', true)
            ->get();

        $subscription = $subscriptions->first(function (WooCommerceWebhookSubscription $item) use ($sourceHost) {
            $domainHost = $this->normalizeHost($item->integration?->domain_link);
            return $sourceHost !== null && $domainHost !== null && $sourceHost === $domainHost;
        });

        if (!$subscription) {
            Log::channel('woocommerce')->warning('WooCommerce webhook ignored (subscription not found/inactive)', [
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'source' => $source,
                'source_host' => $sourceHost,
                'candidate_count' => $subscriptions->count(),
                'candidate_hosts' => $subscriptions
                    ->map(fn (WooCommerceWebhookSubscription $item) => $this->normalizeHost($item->integration?->domain_link))
                    ->filter()
                    ->values()
                    ->all(),
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $integration = $subscription->integration;
        if (!$integration || !$integration->webhook_secret) {
            Log::channel('woocommerce')->warning('WooCommerce webhook ignored (missing integration secret)', [
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
            Log::channel('woocommerce')->warning('WooCommerce webhook signature mismatch', [
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'expected' => Str::limit($expectedSignature, 16, '...'),
                'received' => Str::limit((string) $signature, 16, '...'),
            ]);

            return response()->json(['status' => 'unauthorized'], 401);
        }*/

        if ($topic && $subscription->topic && $subscription->topic !== $topic) {
            Log::channel('woocommerce')->warning('WooCommerce webhook topic mismatch', [
                'webhook_id' => $webhookId,
                'received_topic' => $topic,
                'expected_topic' => $subscription->topic,
            ]);
        }

        Log::channel('woocommerce')->info('WooCommerce webhook received', [
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
        ]);

        ProcessWooCommerceWebhookDeliveryJob::dispatch(
            $rawBody,
            $request->headers->all(),
            $webhookId,
            $topic ?? $subscription->topic,
            $subscription->organization_id,
            $subscription->integration_id,
            $subscription->id
        );

        return response()->json(['status' => 'ok'], 200);
    }

    private function normalizeHost(?string $url): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);
        $host = parse_url($trimmed, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            $host = parse_url('https://' . ltrim($trimmed, '/'), PHP_URL_HOST);
        }

        if (!is_string($host) || $host === '') {
            return null;
        }

        return mb_strtolower($host);
    }
}
