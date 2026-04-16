# WooCommerce add_to_cart context payload

When WooCommerce sends the default webhook topic `action.woocommerce_add_to_cart`, the payload is minimal (`action` + `arg`).
To send full customer/cart details, use a custom WordPress hook handler and post a richer payload to your ChatBerry webhook endpoint.

```php
add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    $endpoint = 'https://YOUR_APP_DOMAIN/woocommerce/webhooks';
    $secret   = 'YOUR_WEBHOOK_SECRET'; // same secret used in your backend validation

    $customer = null;
    if (WC()->customer) {
        $customer = [
            'id'         => WC()->customer->get_id(),
            'first_name' => WC()->customer->get_first_name(),
            'last_name'  => WC()->customer->get_last_name(),
            'email'      => WC()->customer->get_email(),
            'billing'    => [
                'phone' => WC()->customer->get_billing_phone(),
            ],
        ];
    }

    $cart_items = [];
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $item_key => $item) {
            $cart_items[] = [
                'cart_item_key' => $item_key,
                'product_id'    => $item['product_id'] ?? null,
                'variation_id'  => $item['variation_id'] ?? null,
                'quantity'      => $item['quantity'] ?? 1,
                'line_total'    => $item['line_total'] ?? null,
                'name'          => isset($item['data']) && is_object($item['data']) ? $item['data']->get_name() : null,
            ];
        }
    }

    $payload = [
        'action'      => 'woocommerce_add_to_cart',
        'arg'         => $cart_item_key,
        'product_id'  => $product_id,
        'quantity'    => $quantity,
        'variation_id'=> $variation_id,
        'variation'   => $variation,
        'customer'    => $customer,
        'cart'        => [
            'items'     => $cart_items,
            'totals'    => WC()->cart ? WC()->cart->get_totals() : [],
            'item_count'=> WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
        ],
    ];

    $body = wp_json_encode($payload);
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

    wp_remote_post($endpoint, [
        'timeout' => 15,
        'headers' => [
            'Content-Type'            => 'application/json',
            'X-WC-Webhook-Topic'      => 'action.woocommerce_add_to_cart',
            'X-WC-Webhook-Signature'  => $signature,
            'X-WC-Webhook-ID'         => 'custom-add-to-cart',
        ],
        'body'    => $body,
    ]);
}, 10, 6);
```

After this change, ChatBerry can map variables from:
- `chatberry_add_to_cart.customer.*`
- `chatberry_add_to_cart.cart.*`
- `chatberry_add_to_cart.product.*`
