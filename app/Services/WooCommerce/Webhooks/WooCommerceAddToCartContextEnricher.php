<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Models\WooCommerceIntegration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class WooCommerceAddToCartContextEnricher
{
    public function enrich(array $payload, array $headers, ?WooCommerceIntegration $integration): array
    {
        $arg = is_scalar($payload['arg'] ?? null) ? (string) $payload['arg'] : null;
        $productId = $this->resolveProductId($payload, $arg, $integration);
        $inlineProduct = Arr::get($payload, 'product');
        $product = is_array($inlineProduct)
            ? $inlineProduct
            : ($productId && $integration ? $this->fetchProduct($integration, $productId) : null);

        $customerId = $this->extractCustomerId($payload);
        $inlineCustomer = Arr::get($payload, 'customer');
        $customer = is_array($inlineCustomer)
            ? $inlineCustomer
            : ($customerId && $integration ? $this->fetchCustomer($integration, $customerId) : null);

        $cartToken = $this->extractCartToken($payload, $headers);
        $inlineCart = Arr::get($payload, 'cart');
        $cart = is_array($inlineCart)
            ? $inlineCart
            : ($cartToken && $integration ? $this->fetchStoreCart($integration, $cartToken) : null);

        return [
            'action' => $payload['action'] ?? null,
            'arg' => $arg,
            'product_id' => $productId,
            'product' => $product,
            'customer_id' => $customerId,
            'customer' => $customer,
            'cart_token' => $cartToken,
            'cart' => $cart,
            'hints' => [
                'requires_customer_id' => $customerId === null,
                'requires_cart_token' => $cartToken === null,
                'note' => 'Default action webhook payload does not always include customer/cart context.',
            ],
        ];
    }

    private function resolveProductId(array $payload, ?string $arg, ?WooCommerceIntegration $integration): ?int
    {
        $productId = Arr::get($payload, 'product_id');
        if (is_numeric($productId)) {
            return (int) $productId;
        }

        if ($arg !== null && ctype_digit($arg)) {
            return (int) $arg;
        }

        if (!$integration || !$arg || !preg_match('/^[a-f0-9]{32}$/i', $arg)) {
            return null;
        }

        // Woo action payload may send md5(product_id) as `arg`; resolve by scanning product ids.
        $baseUrl = $this->baseUrl($integration);
        $endpoint = $baseUrl . '/wp-json/wc/v3/products';

        for ($page = 1; $page <= 10; $page++) {
            $response = Http::withBasicAuth($integration->consumer_key, $integration->consumer_secret)
                ->timeout(12)
                ->get($endpoint, [
                    'per_page' => 100,
                    'page' => $page,
                    '_fields' => 'id',
                    'orderby' => 'id',
                    'order' => 'desc',
                ]);

            if (!$response->successful()) {
                break;
            }

            $products = $response->json();
            if (!is_array($products) || count($products) === 0) {
                break;
            }

            foreach ($products as $product) {
                $id = $product['id'] ?? null;
                if (is_numeric($id) && md5((string) $id) === strtolower($arg)) {
                    return (int) $id;
                }
            }

            if (count($products) < 100) {
                break;
            }
        }

        return null;
    }

    private function fetchProduct(WooCommerceIntegration $integration, int $productId): ?array
    {
        $url = $this->baseUrl($integration) . '/wp-json/wc/v3/products/' . $productId;

        $response = Http::withBasicAuth($integration->consumer_key, $integration->consumer_secret)
            ->timeout(12)
            ->get($url, [
                '_fields' => 'id,name,price,regular_price,sale_price,permalink,images',
            ]);

        return $response->successful() ? $response->json() : null;
    }

    private function fetchCustomer(WooCommerceIntegration $integration, int $customerId): ?array
    {
        $url = $this->baseUrl($integration) . '/wp-json/wc/v3/customers/' . $customerId;

        $response = Http::withBasicAuth($integration->consumer_key, $integration->consumer_secret)
            ->timeout(12)
            ->get($url, [
                '_fields' => 'id,email,first_name,last_name,billing,shipping',
            ]);

        return $response->successful() ? $response->json() : null;
    }

    private function fetchStoreCart(WooCommerceIntegration $integration, string $cartToken): ?array
    {
        $url = $this->baseUrl($integration) . '/wp-json/wc/store/v1/cart';

        $response = Http::timeout(12)
            ->withHeaders([
                'Cart-Token' => $cartToken,
            ])
            ->get($url);

        return $response->successful() ? $response->json() : null;
    }

    private function extractCustomerId(array $payload): ?int
    {
        $customerId = Arr::get($payload, 'customer_id')
            ?? Arr::get($payload, 'customer.id')
            ?? Arr::get($payload, 'context.customer_id');

        return is_numeric($customerId) ? (int) $customerId : null;
    }

    private function extractCartToken(array $payload, array $headers): ?string
    {
        $token = Arr::get($payload, 'cart_token')
            ?? Arr::get($payload, 'context.cart_token')
            ?? Arr::get($headers, 'x-wc-store-api-cart-token')
            ?? Arr::get($headers, 'cart-token');

        if (is_array($token)) {
            $token = $token[0] ?? null;
        }

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function baseUrl(WooCommerceIntegration $integration): string
    {
        $base = rtrim($integration->domain_link, '/');
        return str_replace('/wp-admin', '', $base);
    }
}
