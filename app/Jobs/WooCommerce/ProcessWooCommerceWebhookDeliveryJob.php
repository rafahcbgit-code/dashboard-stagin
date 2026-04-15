<?php

namespace App\Jobs\WooCommerce;

use App\Jobs\WooCommerce\HandleWooCommerceWebhookDeliveryJob;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceWebhookDelivery;
use App\Services\WooCommerce\Webhooks\WooCommerceAddToCartContextEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class ProcessWooCommerceWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly ?string $webhookId,
        public readonly ?string $topic,
        public readonly ?int $organizationId,
        public readonly ?int $integrationId,
        public readonly ?int $subscriptionId
    ) {
    }

    public function handle(WooCommerceAddToCartContextEnricher $addToCartContextEnricher): void
    {
        $payload = json_decode($this->rawBody, true) ?? [];

        $headers = collect($this->headers)
            ->map(fn ($value) => is_array($value) ? $value[0] : $value)
            ->toArray();
        $deliveryId = (string) ($headers['x-wc-webhook-delivery-id'] ?? '');
        $payloadHash = hash('sha256', $this->rawBody . '|' . $deliveryId);

        if (($this->topic ?? '') === 'action.woocommerce_add_to_cart' && $this->integrationId) {
            $integration = WooCommerceIntegration::find($this->integrationId);
            $payload['chatberry_add_to_cart'] = $addToCartContextEnricher->enrich($payload, $headers, $integration);
        }
        $dedupeKey = $this->buildDedupeKey($payload);

        $existing = WooCommerceWebhookDelivery::where('payload_hash', $payloadHash)
            ->when($this->webhookId, fn ($q) => $q->where('webhook_id', $this->webhookId))
            ->first();

        if ($existing) {
            $existing->increment('attempts');
            Log::info('WooCommerce webhook duplicate ignored', [
                'payload_hash' => $payloadHash,
                'webhook_id' => $this->webhookId,
                'organization_id' => $this->organizationId,
            ]);
            return;
        }

        $stored = WooCommerceWebhookDelivery::create([
            'uuid' => Uuid::uuid4()->toString(),
            'webhook_id' => $this->webhookId,
            'topic' => $this->topic,
            'dedupe_key' => $dedupeKey,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'headers' => $headers,
            'integration_id' => $this->integrationId,
            'subscription_id' => $this->subscriptionId,
            'organization_id' => $this->organizationId,
            'status' => 'received',
            'attempts' => 1,
        ]);

        if (!$stored) {
            Log::warning('WooCommerce webhook could not be stored', [
                'payload_hash' => $payloadHash,
                'webhook_id' => $this->webhookId,
            ]);
            return;
        }

        if (($this->topic ?? '') === 'action.woocommerce_add_to_cart' && $dedupeKey && $this->organizationId) {
            WooCommerceWebhookDelivery::where('organization_id', $this->organizationId)
                ->where('topic', 'action.woocommerce_add_to_cart')
                ->where('dedupe_key', $dedupeKey)
                ->where('id', '<', $stored->id)
                ->whereIn('status', ['pending', 'received', 'processing', 'scheduled', 'failed'])
                ->update([
                    'status' => 'skipped',
                    'processed_at' => now(),
                    'error' => 'superseded_by_newer_add_to_cart',
                ]);
        }

        HandleWooCommerceWebhookDeliveryJob::dispatch($stored->id);

        Log::info('WooCommerce webhook stored', [
            'webhook_id' => $this->webhookId,
            'topic' => $this->topic,
            'organization_id' => $this->organizationId,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    private function buildDedupeKey(array $payload): ?string
    {
        if (($this->topic ?? '') !== 'action.woocommerce_add_to_cart') {
            return null;
        }

        $cartToken = data_get($payload, 'chatberry_add_to_cart.cart_token')
            ?? data_get($payload, 'arg');
        if (is_scalar($cartToken) && trim((string) $cartToken) !== '') {
            return 'cart:' . trim((string) $cartToken);
        }

        $customerId = data_get($payload, 'chatberry_add_to_cart.customer_id');
        if (is_scalar($customerId) && trim((string) $customerId) !== '') {
            return 'customer:' . trim((string) $customerId);
        }

        $phone = data_get($payload, 'chatberry_add_to_cart.customer.billing.phone')
            ?? data_get($payload, 'chatberry_add_to_cart.customer.shipping.phone')
            ?? data_get($payload, 'chatberry_add_to_cart.cart.billing_address.phone')
            ?? data_get($payload, 'chatberry_add_to_cart.cart.shipping_address.phone');

        if (is_scalar($phone) && trim((string) $phone) !== '') {
            return 'phone:' . preg_replace('/\D+/', '', (string) $phone);
        }

        return null;
    }
}
