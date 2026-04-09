<?php

namespace App\Services\Zid\Webhooks\Schemas;

class ZidAbandonedCartFieldSchema
{
    /**
     * Commonly used fields for the Zid `abandoned_cart.created` event.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event Name'],
            ['path' => 'id', 'label' => 'Webhook ID'],
            ['path' => 'cart_id', 'label' => 'Cart ID'],
            ['path' => 'session_id', 'label' => 'Session ID'],
            ['path' => 'phase', 'label' => 'Phase'],
            ['path' => 'url', 'label' => 'Checkout URL'],
            ['path' => 'cart_total', 'label' => 'Cart Total'],
            ['path' => 'cart_total_string', 'label' => 'Cart Total (formatted)'],
            ['path' => 'created_at', 'label' => 'Cart Created At'],
            ['path' => 'customer_id', 'label' => 'Customer ID'],
            ['path' => 'customer_name', 'label' => 'Customer Name'],
            ['path' => 'customer_email', 'label' => 'Customer Email'],
            ['path' => 'customer_mobile', 'label' => 'Customer Mobile'],
            ['path' => 'store_id', 'label' => 'Store ID'],
            ['path' => 'city_id', 'label' => 'City ID'],
            ['path' => 'products_count', 'label' => 'Products Count'],
            ['path' => 'reminders_count', 'label' => 'Reminders Count'],
            ['path' => 'store_url', 'label' => 'Store URL'],
            ['path' => 'order_id', 'label' => 'Order ID'],
        ];
    }
}
