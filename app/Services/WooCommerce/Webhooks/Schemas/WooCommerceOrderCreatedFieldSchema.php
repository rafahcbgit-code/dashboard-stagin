<?php

namespace App\Services\WooCommerce\Webhooks\Schemas;

class WooCommerceOrderCreatedFieldSchema
{
    public function fields(): array
    {
        return [
            [
                'label' => 'Order ID',
                'path' => 'id',
            ],
            [
                'label' => 'Order Number',
                'path' => 'number',
            ],
            [
                'label' => 'Status',
                'path' => 'status',
            ],
            [
                'label' => 'Currency',
                'path' => 'currency',
            ],
            [
                'label' => 'Total',
                'path' => 'total',
            ],
            [
                'label' => 'Total Tax',
                'path' => 'total_tax',
            ],
            [
                'label' => 'Customer ID',
                'path' => 'customer_id',
            ],
            [
                'label' => 'Billing First Name',
                'path' => 'billing.first_name',
            ],
            [
                'label' => 'Billing City',
                'path' => 'billing.city',
            ],
            [
                'label' => 'Billing Country',
                'path' => 'billing.country',
            ],
            [
                'label' => 'Billing Last Name',
                'path' => 'billing.last_name',
            ],
            [
                'label' => 'Billing Email',
                'path' => 'billing.email',
            ],
            [
                'label' => 'Billing Phone',
                'path' => 'billing.phone',
            ],
            [
                'label' => 'Shipping Phone',
                'path' => 'shipping.phone',
            ],
            [
                'label' => 'Payment URL',
                'path' => 'payment_url',
            ],
            [
                'label' => 'First Item Name',
                'path' => 'line_items.0.name',
            ],
            [
                'label' => 'First Item Quantity',
                'path' => 'line_items.0.quantity',
            ],
            [
                'label' => 'First Item Total',
                'path' => 'line_items.0.total',
            ],
            [
                'label' => 'Order Created At',
                'path' => 'date_created',
            ],
        ];
    }
}
