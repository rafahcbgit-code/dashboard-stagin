<?php

namespace App\Services\Webhooks;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ShopifyGDPRWebhookService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function registerComplianceWebhooks($shop, $accessToken, $baseUrl)
    {
        $webhooks = [
            'customers/data_request' => '/shopify/webhooks/customers/data_request',
            'customers/redact' => '/shopify/webhooks/customers/redact',
            'shop/redact' => '/shopify/webhooks/shop/redact',
        ];

        foreach ($webhooks as $topic => $endpoint) {
            try {
                $this->client->post("https://{$shop}/admin/api/2026-01/webhooks.json", [
                    'headers' => [
                        'X-Shopify-Access-Token' => $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'webhook' => [
                            'topic' => $topic,
                            'address' => $baseUrl . $endpoint,
                            'format' => 'json'
                        ]
                    ]
                ]);

                Log::info("Webhook registered: $topic");

            } catch (\Exception $e) {
                Log::error("Failed to register webhook: $topic", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}