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
                'label' => 'Billing Address 1',
                'path' => 'billing.address_1',
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
                'label' => 'Shipping First Name',
                'path' => 'shipping.first_name',
            ],
            [
                'label' => 'Shipping Last Name',
                'path' => 'shipping.last_name',
            ],
            [
                'label' => 'Shipping Address 1',
                'path' => 'shipping.address_1',
            ],
            [
                'label' => 'Shipping Address 2',
                'path' => 'shipping.address_2',
            ],
            [
                'label' => 'Shipping City',
                'path' => 'shipping.city',
            ],
            [
                'label' => 'Shipping State',
                'path' => 'shipping.state',
            ],
            [
                'label' => 'Shipping Postcode',
                'path' => 'shipping.postcode',
            ],
            [
                'label' => 'Shipping Country',
                'path' => 'shipping.country',
            ],
            [
                'label' => 'Payment URL',
                'path' => 'payment_url',
            ],
            [
                'label' => 'Invoice PDF URL',
                'path' => 'invoice_pdf_url',
            ],
            [
                'label' => 'All Items Summary',
                'path' => 'chatberry_order.products_summary',
            ],
            [
                'label' => 'Order Created At',
                'path' => 'date_created',
            ],
        ];
    }
}
