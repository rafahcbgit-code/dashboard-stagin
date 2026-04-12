<?php

namespace App\Services\Zid\Webhooks\Schemas;

class ZidOrderStatusUpdatedFieldSchema
{
    /**
     * Fields relevant for Zid `order.status.update` payloads.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'id', 'label' => 'Order ID'],
            ['path' => 'code', 'label' => 'Order Code'],
            ['path' => 'order_status.code', 'label' => 'Status Code'],
            ['path' => 'order_status.name', 'label' => 'Status Name'],
            ['path' => 'order_total_string', 'label' => 'Order Total'],
            ['path' => 'transaction_amount_string', 'label' => 'Transaction Amount'],
            ['path' => 'payment.network', 'label' => 'Payment Network'],
            ['path' => 'customer.id', 'label' => 'Customer ID'],
            ['path' => 'customer.name', 'label' => 'Customer Name'],
            ['path' => 'customer.email', 'label' => 'Customer Email'],
            ['path' => 'customer.mobile', 'label' => 'Customer Mobile'],
            ['path' => 'customer.verified', 'label' => 'Customer Verified'],
            ['path' => 'shipping.address.formatted_address', 'label' => 'Shipping Address'],
            ['path' => 'histories.0.comment', 'label' => 'Latest History Comment'],
            ['path' => 'histories.0.changed_by_details.by', 'label' => 'Changed By'],
            ['path' => 'histories.0.created_at', 'label' => 'History Timestamp'],
        ];
    }
}
