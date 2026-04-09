<?php

namespace App\Services\Zid\Webhooks\Schemas;

class ZidOrderCreatedFieldSchema
{
    /**
     * Returns a list of relevant payload paths for order.create webhooks.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'id', 'label' => 'Order ID'],
            ['path' => 'code', 'label' => 'Order Code'],
            ['path' => 'order_total', 'label' => 'Order Total'],
            ['path' => 'order_total_string', 'label' => 'Order Total (formatted)'],
            ['path' => 'customer.id', 'label' => 'Customer ID'],
            ['path' => 'customer.name', 'label' => 'Customer Name'],
            ['path' => 'customer.email', 'label' => 'Customer Email'],
            ['path' => 'customer.mobile', 'label' => 'Customer Mobile'],
            ['path' => 'payment.method.name', 'label' => 'Payment Method'],
            ['path' => 'shipping.address.formatted_address', 'label' => 'Shipping Address'],
            ['path' => 'order_url', 'label' => 'Order URL'],
            ['path' => 'store_url', 'label' => 'Store URL'],
        ];
    }
}
