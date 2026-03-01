<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaAbandonedCartFieldSchema
{
    /**
     * Fixed (non-dynamic) list of commonly used fields for `abandoned.cart`.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'merchant', 'label' => 'Merchant ID'],
            ['path' => 'created_at', 'label' => 'Webhook Created At'],

            ['path' => 'data.id', 'label' => 'Cart ID'],
            ['path' => 'data.age_in_minutes', 'label' => 'Age (Minutes)'],

            ['path' => 'data.total.amount', 'label' => 'Total Amount'],
            ['path' => 'data.total.currency', 'label' => 'Total Currency'],
            ['path' => 'data.subtotal.amount', 'label' => 'Subtotal Amount'],
            ['path' => 'data.total_discount.amount', 'label' => 'Discount Amount'],

            ['path' => 'data.checkout_url', 'label' => 'Checkout URL'],

            ['path' => 'data.customer.id', 'label' => 'Customer ID'],
            ['path' => 'data.customer.name', 'label' => 'Customer Name'],
            ['path' => 'data.customer.mobile', 'label' => 'Customer Mobile'],
            ['path' => 'data.customer.email', 'label' => 'Customer Email'],
            ['path' => 'data.customer.country', 'label' => 'Customer Country'],
            ['path' => 'data.customer.city', 'label' => 'Customer City'],

            ['path' => 'data.coupon.code', 'label' => 'Coupon Code'],
            ['path' => 'data.coupon.amount', 'label' => 'Coupon Amount'],
            ['path' => 'data.coupon.type', 'label' => 'Coupon Type'],
            ['path' => 'data.coupon.expiry_date', 'label' => 'Coupon Expiry Date'],

            ['path' => 'data.items.0.product_id', 'label' => 'First Item Product ID'],
            ['path' => 'data.items.0.quantity', 'label' => 'First Item Quantity'],
        ];
    }
}

