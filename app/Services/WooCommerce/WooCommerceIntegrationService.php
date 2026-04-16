<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceIntegration;
use App\Services\WooCommerce\Contracts\WooCommerceIntegrationRepositoryInterface;
use App\Services\WooCommerce\DTOs\WooCommerceCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceIntegrationService
{
    public function __construct(
        private WooCommerceIntegrationRepositoryInterface $repository
    ) {
    }

    public function getForOrganization(int $organizationId): ?WooCommerceIntegration
    {
        return $this->repository->getByOrganization($organizationId);
    }

    public function storeCredentials(
        WooCommerceCredentials $credentials,
        int $organizationId,
        int $userId
    ): WooCommerceIntegration {
        $data = $credentials->toArray();
        $data['user_id'] = $userId;

        $integration = $this->repository->createOrUpdate($organizationId, $data);

        if (!$integration->webhook_secret) {
            $integration->webhook_secret = Str::random(64);
            $integration->save();
        }

        return $integration;
    }

    public function testConnection(WooCommerceIntegration $integration): array
    {
        return $this->sendRequest(
            $integration,
            'test_connection',
            'GET',
            '/wp-json/wc/v3/system_status'
        );
    }

    public function createTopicSubscription(WooCommerceIntegration $integration, string $topic): array
    {
        $payload = [
            'name' => $this->getWebhookName($topic),
            'topic' => $topic,
            'delivery_url' => config('woocommerce.webhook_delivery_url'),
            'secret' => $integration->webhook_secret,
            'status' => 'active',
        ];

        return $this->sendRequest(
            $integration,
            'webhook_create',
            'POST',
            '/wp-json/wc/v3/webhooks',
            $payload
        );
    }

    public function deleteWebhookSubscription(WooCommerceIntegration $integration, string $webhookId): void
    {
        $this->sendRequest(
            $integration,
            'webhook_delete',
            'DELETE',
            "/wp-json/wc/v3/webhooks/{$webhookId}"
        );
    }

    private function sendRequest(
        WooCommerceIntegration $integration,
        string $context,
        string $method,
        string $path,
        array $payload = []
    ): array {
        $url = $this->buildBaseUrl($integration) . $path;

        $this->logRequestDetails($integration, $url, $context, $method, [
            'payload' => $payload,
            'timeout' => 15,
        ]);

        $http = Http::withBasicAuth($integration->consumer_key, $integration->consumer_secret)
            ->timeout(15);

        return match ($method) {
            'POST' => $this->handleResponse($integration, $url, $context, $http->post($url, $payload)),
            'DELETE' => $this->handleResponse($integration, $url, $context, $http->delete($url)),
            default => $this->handleResponse($integration, $url, $context, $http->get($url)),
        };
    }

    private function handleResponse(
        WooCommerceIntegration $integration,
        string $url,
        string $context,
        $response
    ): array {
        if (!$response->successful()) {
            $body = $this->sanitizeBody($response->body());
            throw new \RuntimeException("WooCommerce responded {$response->status()}: {$body}");
        }

        $this->logResponseDetails($integration, $url, $context, $response);

        return $response->json();
    }

    private function buildBaseUrl(WooCommerceIntegration $integration): string
    {
        $base = rtrim($integration->domain_link, '/');
        return str_replace('/wp-admin', '', $base);
    }

    private function getWebhookName(string $topic): string
    {
        return match ($topic) {
            'order.created' => 'Order Created Hook',
            'action.woocommerce_order_status_changed' => 'Order Status Changed Hook',
            'action.woocommerce_add_to_cart' => 'Add to Cart Listener',
            'order.updated' => 'Order Updated Hook',
            default => 'WooCommerce Hook',
        };
    }

    private function logRequestDetails(
        WooCommerceIntegration $integration,
        string $url,
        string $context,
        string $method,
        array $extra = []
    ): void {
        Log::channel('woocommerce')->info('WooCommerce request', [
            'organization_id' => $integration->organization_id,
            'context' => $context,
            'method' => $method,
            'url' => $url,
            'consumer_key' => Str::mask($integration->consumer_key, '*', 4, -4),
            'consumer_secret' => Str::mask($integration->consumer_secret, '*', 4, -4),
            'details' => $extra,
        ]);
    }

    private function logResponseDetails(
        WooCommerceIntegration $integration,
        string $url,
        string $context,
        $response
    ): void {
        Log::channel('woocommerce')->info('WooCommerce response', [
            'organization_id' => $integration->organization_id,
            'context' => $context,
            'url' => $url,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $this->sanitizeBody($response->body(), 300),
        ]);
    }

    private function sanitizeBody(string $rawBody, int $limit = 200): string
    {
        $clean = strip_tags($rawBody);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return Str::limit(trim($clean), $limit);
    }
}
