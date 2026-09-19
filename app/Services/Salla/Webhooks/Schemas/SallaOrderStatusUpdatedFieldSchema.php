<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaOrderStatusUpdatedFieldSchema
{
    /**
     * Fixed (non-dynamic) list of commonly used fields for `order.status.updated`.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'merchant', 'label' => 'Merchant ID'],
            ['path' => 'created_at', 'label' => 'Webhook Created At'],

            // Status update activity payload
            ['path' => 'data.id', 'label' => 'Status Update ID'],
            ['path' => 'data.status', 'label' => 'Status (Display Name)'],
            ['path' => 'data.customized.slug', 'label' => 'Status Slug'],
            ['path' => 'data.customized.name', 'label' => 'Status Name'],
            ['path' => 'data.note', 'label' => 'Note'],
            ['path' => 'data.created_by.name', 'label' => 'Updated By (Name)'],

            // Order
            ['path' => 'data.order.id', 'label' => 'Order ID'],
            ['path' => 'data.order.reference_id', 'label' => 'Order Reference'],
            ['path' => 'data.order.payment_method', 'label' => 'Payment Method'],
            ['path' => 'data.order.currency', 'label' => 'Currency'],
            ['path' => 'data.order.amounts.total.amount', 'label' => 'Total Amount'],
            ['path' => 'data.order.receipt_image', 'label' => 'Receipt Image'],

            ['path' => 'data.order.urls.customer', 'label' => 'Customer Order URL'],
            ['path' => 'data.order.urls.admin', 'label' => 'Admin Order URL'],

            // Shipping tracking links. Salla provides the first path on the order's
            // primary shipment and the second path in the shipments collection.
            ['path' => 'data.order.shipping.shipment.tracking_link', 'label' => 'Tracking Link (Primary Shipment)'],
            ['path' => 'data.order.shipments.0.tracking_link', 'label' => 'Tracking Link (First Shipment)'],

            // Customer
            ['path' => 'data.order.customer.id', 'label' => 'Customer ID'],
            ['path' => 'data.order.customer.name', 'label' => 'Customer Name'],
            ['path' => 'data.order.customer.mobile', 'label' => 'Customer Mobile'],
            ['path' => 'data.order.customer.email', 'label' => 'Customer Email'],
            ['path' => 'data.order.customer.city', 'label' => 'Customer City'],

            // Items (first item)
            ['path' => 'data.order.items.0.name', 'label' => 'First Item Name'],
            ['path' => 'data.order.items.0.quantity', 'label' => 'First Item Quantity'],
            ['path' => 'data.order.items.0.amounts.total.amount', 'label' => 'First Item Total Amount'],
        ];
    }
}

