<?php

namespace App\Services\Shopify\Webhooks\Schemas;

class ShopifyOrderFulfilledFieldSchema
{
    /**
     * Provide a small sample of fields that are usually present when a fulfillment is created/updated.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'id', 'label' => 'Order ID'],
            ['path' => 'name', 'label' => 'Order Name'],
            ['path' => 'order_number', 'label' => 'Order Number'],
            ['path' => 'created_at', 'label' => 'Created At'],
            ['path' => 'processed_at', 'label' => 'Processed At'],
            ['path' => 'financial_status', 'label' => 'Financial Status'],
            ['path' => 'fulfillment_status', 'label' => 'Fulfillment Status'],

            ['path' => 'customer.first_name', 'label' => 'Customer First Name'],
            ['path' => 'customer.last_name', 'label' => 'Customer Last Name'],
            ['path' => 'customer.phone', 'label' => 'Customer Phone'],
            ['path' => 'shipping_address.phone', 'label' => 'Shipping Phone'],

            ['path' => 'fulfillments.0.id', 'label' => 'Fulfillment ID'],
            ['path' => 'fulfillments.0.status', 'label' => 'Fulfillment Status'],
            ['path' => 'fulfillments.0.tracking_company', 'label' => 'Carrier'],
            ['path' => 'fulfillments.0.tracking_number', 'label' => 'Tracking Number'],
            ['path' => 'fulfillments.0.tracking_url', 'label' => 'Tracking URL'],
            ['path' => 'fulfillments.0.tracking_numbers.0', 'label' => 'First Tracking Number'],
            ['path' => 'fulfillments.0.updated_at', 'label' => 'Fulfillment Updated At'],
            ['path' => 'fulfillments.0.line_items.0.name', 'label' => 'First Fulfilled Item Name'],
            ['path' => 'fulfillments.0.line_items.0.quantity', 'label' => 'First Fulfilled Item Quantity'],
            ['path' => 'fulfillments.0.tracking_url', 'label' => 'Tracking URL'],
            ['path' => 'fulfillments.0.tracking_urls.0', 'label' => 'First Tracking URL'],
            ['path' => 'order_status_url', 'label' => 'Order Status URL'],
            ['path' => 'total_price_set.presentment_money.amount', 'label' => 'Total Price Presentment'],
            ['path' => 'current_total_price_set.presentment_money.amount', 'label' => 'Current Total Presentment'],
            ['path' => 'current_subtotal_price_set.presentment_money.amount', 'label' => 'Current Subtotal Presentment'],
        ];
    }
}
