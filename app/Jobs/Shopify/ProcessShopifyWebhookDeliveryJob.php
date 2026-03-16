<?php

namespace App\Jobs\Shopify;

use App\Jobs\Shopify\HandleShopifyWebhookDeliveryJob;
use App\Models\ShopifyWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class ProcessShopifyWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly int $shopifyStoreId,
        public readonly ?int $organizationId
    ) {}

    public function handle(): void
    {
        $payload = json_decode($this->rawBody, true) ?? [];
        $payloadHash = hash('sha256', $this->rawBody);

        $existing = ShopifyWebhookDelivery::where('payload_hash', $payloadHash)
            ->where('shopify_store_id', $this->shopifyStoreId)
            ->first();
        if ($existing) {
            $existing->increment('attempts');
            Log::info('Shopify webhook duplicate ignored', [
                'payload_hash' => $payloadHash,
                'event_id' => data_get($this->headers, 'x-shopify-event-id'),
                'shopify_store_id' => $this->shopifyStoreId,
            ]);
            return;
        }

        $headers = collect($this->headers)
            ->map(fn($value) => is_array($value) ? $value[0] : $value)
            ->toArray();

        $stored = ShopifyWebhookDelivery::create([
            'uuid' => Uuid::uuid4()->toString(),
            'shop_domain' => $headers['x-shopify-shop-domain'] ?? data_get($payload, 'shop_domain'),
            'topic' => $headers['x-shopify-topic'] ?? null,
            'event_id' => $headers['x-shopify-event-id'] ?? null,
            'payload_hash' => $payloadHash,
            'hmac' => $headers['x-shopify-hmac-sha256'] ?? null,
            'payload' => $payload,
            'headers' => $headers,
            'shopify_store_id' => $this->shopifyStoreId,
            'organization_id' => $this->organizationId,
            'status' => 'received',
            'attempts' => 1,
        ]);
        if (!$stored) {
            Log::warning('Shopify webhook could not be stored', ['payload_hash' => $payloadHash]);
            return;
        }

        HandleShopifyWebhookDeliveryJob::dispatch($stored->id);

        Log::info('Shopify webhook stored', [
            'shop_domain' => data_get($this->headers, 'x-shopify-shop-domain'),
            'topic' => data_get($this->headers, 'x-shopify-topic'),
            'event_id' => data_get($this->headers, 'x-shopify-event-id'),
        ]);
    }
}
