<?php

namespace App\Jobs\WooCommerce;

use App\Jobs\WooCommerce\HandleWooCommerceWebhookDeliveryJob;
use App\Models\WooCommerceWebhookDelivery;
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

    public function handle(): void
    {
        $payload = json_decode($this->rawBody, true) ?? [];
        $payloadHash = hash('sha256', $this->rawBody);

        $headers = collect($this->headers)
            ->map(fn ($value) => is_array($value) ? $value[0] : $value)
            ->toArray();

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

        HandleWooCommerceWebhookDeliveryJob::dispatch($stored->id);

        Log::info('WooCommerce webhook stored', [
            'webhook_id' => $this->webhookId,
            'topic' => $this->topic,
            'organization_id' => $this->organizationId,
        ]);
    }
}
