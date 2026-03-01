<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaOrderCreatedFieldSchema
{
    /**
     * ثابتة (غير ديناميكية) - قائمة الحقول الأكثر استخدامًا في order.created.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'merchant', 'label' => 'Merchant ID'],
            ['path' => 'created_at', 'label' => 'Webhook Created At'],

            ['path' => 'data.id', 'label' => 'Order ID'],
            ['path' => 'data.reference_id', 'label' => 'Order Reference'],
            ['path' => 'data.payment_method', 'label' => 'Payment Method'],
            ['path' => 'data.shipping_status', 'label' => 'Shipping Status'],
            ['path' => 'data.is_pending_payment', 'label' => 'Is Pending Payment'],
            ['path' => 'data.accepted_payment_methods', 'label' => 'Accepted Payment Methods (All)'],
            ['path' => 'data.accepted_payment_methods.0', 'label' => 'Accepted Payment Method (First)'],

            ['path' => 'data.status.name', 'label' => 'Order Status (Name)'],
            ['path' => 'data.status.slug', 'label' => 'Order Status (Slug)'],

            ['path' => 'data.currency', 'label' => 'Currency'],
            ['path' => 'data.amounts.sub_total.amount', 'label' => 'Subtotal Amount'],
            ['path' => 'data.amounts.shipping_cost.amount', 'label' => 'Shipping Cost Amount'],
            ['path' => 'data.amounts.tax.amount.amount', 'label' => 'Tax Amount'],
            ['path' => 'data.amounts.total.amount', 'label' => 'Total Amount'],

            ['path' => 'data.urls.customer', 'label' => 'Customer Order URL'],
            ['path' => 'data.urls.admin', 'label' => 'Admin Order URL'],
            ['path' => 'data.payment_actions.remaining_action.checkout_url', 'label' => 'Checkout URL'],

            ['path' => 'data.customer.id', 'label' => 'Customer ID'],
            ['path' => 'data.customer.full_name', 'label' => 'Customer Full Name'],
            ['path' => 'data.customer.first_name', 'label' => 'Customer First Name'],
            ['path' => 'data.customer.last_name', 'label' => 'Customer Last Name'],
            ['path' => 'data.customer.mobile_code', 'label' => 'Customer Mobile Code'],
            ['path' => 'data.customer.mobile', 'label' => 'Customer Mobile (No Code)'],

            ['path' => 'data.shipping.receiver.name', 'label' => 'Receiver Name'],
            ['path' => 'data.shipping.receiver.phone', 'label' => 'Receiver Phone'],
            ['path' => 'data.shipping.address.city', 'label' => 'Shipping City'],
            ['path' => 'data.shipping.address.shipping_address', 'label' => 'Shipping Address'],

            // Tracking
            ['path' => 'data.shipping.shipment.tracking_link', 'label' => 'Tracking Link (Shipping Shipment)'],
        
            ['path' => 'data.items.0.name', 'label' => 'First Item Name'],
            ['path' => 'data.items.0.quantity', 'label' => 'First Item Quantity'],
            ['path' => 'data.items.0.amounts.total.amount', 'label' => 'First Item Total Amount'],

            ['path' => 'data.store.id', 'label' => 'Store ID'],
            ['path' => 'data.store.username', 'label' => 'Store Username'],
            ['path' => 'data.store.name.ar', 'label' => 'Store Name (AR)'],
        ];
    }
}
