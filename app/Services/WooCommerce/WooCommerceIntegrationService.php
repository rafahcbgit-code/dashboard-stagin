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

        return $this->repository->createOrUpdate($organizationId, $data);
    }

    /**
     * Attempt to reach WooCommerce system_status using stored credentials.
     *
     * @throws \RuntimeException on failure
     */
    public function testConnection(WooCommerceIntegration $integration): array
    {
        $base = rtrim($integration->domain_link, '/');
        $sanitizedBase = str_replace('/wp-admin', '', $base);
        $url = $sanitizedBase . '/wp-json/wc/v3/system_status';

        $http = Http::withBasicAuth($integration->consumer_key, $integration->consumer_secret)
            ->timeout(15);

        $method = 'GET';
        $this->logRequestDetails($integration, $url, $method, [
            'timeout' => 15,
        ]);

        $response = $http->get($url);

        if (!$response->successful()) {
            $body = Str::limit($response->body(), 200);
            throw new \RuntimeException("WooCommerce responded {$response->status()}: {$body}");
        }

        $this->logResponseDetails($integration, $url, $response);

        return $response->json();
    }

    private function logRequestDetails(WooCommerceIntegration $integration, string $url, string $method, array $extra = []): void
    {
        Log::info('WooCommerce test connection request', [
            'organization_id' => $integration->organization_id,
            'method' => $method,
            'url' => $url,
            'consumer_key' => Str::mask($integration->consumer_key, '*', 4, -4),
            'consumer_secret' => Str::mask($integration->consumer_secret, '*', 4, -4),
            'details' => $extra,
        ]);
    }

    private function logResponseDetails(WooCommerceIntegration $integration, string $url, $response): void
    {
        Log::info('WooCommerce test connection response', [
            'organization_id' => $integration->organization_id,
            'url' => $url,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => Str::limit($response->body(), 300),
        ]);
    }
}
