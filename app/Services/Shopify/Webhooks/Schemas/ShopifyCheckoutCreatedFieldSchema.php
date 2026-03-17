<?php

namespace App\Services\Shopify\Webhooks\Schemas;

class ShopifyCheckoutCreatedFieldSchema
{
    /**
     * Schema fields for Shopify checkouts/create payloads.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'id', 'label' => 'Checkout ID'],
            ['path' => 'token', 'label' => 'Checkout Token'],
            ['path' => 'cart_token', 'label' => 'Cart Token'],
            ['path' => 'email', 'label' => 'Email'],
            ['path' => 'phone', 'label' => 'Phone'],
            ['path' => 'created_at', 'label' => 'Created At'],
            ['path' => 'updated_at', 'label' => 'Updated At'],
            ['path' => 'landing_site', 'label' => 'Landing Site'],
            ['path' => 'referring_site', 'label' => 'Referring Site'],
            ['path' => 'abandoned_checkout_url', 'label' => 'Abandoned Checkout URL'],
            ['path' => 'subtotal_price', 'label' => 'Subtotal'],
            ['path' => 'total_price', 'label' => 'Total'],
            ['path' => 'currency', 'label' => 'Currency'],
            ['path' => 'source_name', 'label' => 'Source'],
            ['path' => 'total_discounts', 'label' => 'Total Discounts'],
            ['path' => 'line_items.0.title', 'label' => 'First Line Item Title'],
            ['path' => 'line_items.0.quantity', 'label' => 'First Line Item Quantity'],
            ['path' => 'line_items.0.price', 'label' => 'First Line Item Price'],
            ['path' => 'line_items.0.sku', 'label' => 'First Line Item SKU'],
            ['path' => 'customer.first_name', 'label' => 'Customer First Name'],
            ['path' => 'customer.last_name', 'label' => 'Customer Last Name'],
            ['path' => 'customer.email', 'label' => 'Customer Email'],
            ['path' => 'customer.phone', 'label' => 'Customer Phone'],
        ];
    }
}

