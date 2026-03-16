<?php

namespace App\Services\Shopify\Webhooks\Schemas;

class ShopifyOrderCreatedFieldSchema
{
    /**
     * Define introductory webhook paths for Shopify order.created payloads.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'id', 'label' => 'Order ID'],
            ['path' => 'name', 'label' => 'Order Name'],
            ['path' => 'order_number', 'label' => 'Order Number'],
            ['path' => 'reference', 'label' => 'Order Reference'],
            ['path' => 'created_at', 'label' => 'Created At'],
            ['path' => 'processed_at', 'label' => 'Processed At'],
            ['path' => 'currency', 'label' => 'Currency'],
            ['path' => 'total_price', 'label' => 'Total Price'],
            ['path' => 'subtotal_price', 'label' => 'Subtotal'],
            ['path' => 'total_shipping_price_set.shop_money.amount', 'label' => 'Shipping Amount'],
            ['path' => 'total_tax', 'label' => 'Total Tax'],
            ['path' => 'source_name', 'label' => 'Source'],
            ['path' => 'tags', 'label' => 'Tags'],

            ['path' => 'customer.first_name', 'label' => 'Customer First Name'],
            ['path' => 'customer.last_name', 'label' => 'Customer Last Name'],
            ['path' => 'customer.email', 'label' => 'Customer Email'],
            ['path' => 'customer.phone', 'label' => 'Customer Phone'],

            ['path' => 'shipping_address.address1', 'label' => 'Shipping Address'],
            ['path' => 'shipping_address.city', 'label' => 'Shipping City'],
            ['path' => 'shipping_address.country', 'label' => 'Shipping Country'],
            ['path' => 'shipping_address.phone', 'label' => 'Shipping Phone'],

            ['path' => 'billing_address.address1', 'label' => 'Billing Address'],
            ['path' => 'billing_address.city', 'label' => 'Billing City'],
            ['path' => 'billing_address.country', 'label' => 'Billing Country'],

            ['path' => 'line_items.0.name', 'label' => 'First Line Item Name'],
            ['path' => 'line_items.0.quantity', 'label' => 'First Line Item Quantity'],
            ['path' => 'line_items.0.price', 'label' => 'First Line Item Price'],
            ['path' => 'line_items.0.sku', 'label' => 'First Line Item SKU'],

            ['path' => 'shipping_lines.0.title', 'label' => 'Shipping Method'],
            ['path' => 'shipping_lines.0.price', 'label' => 'Shipping Line Price'],
        ];
    }
}
