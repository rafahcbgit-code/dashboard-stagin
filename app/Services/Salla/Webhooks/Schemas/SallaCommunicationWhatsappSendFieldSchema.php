<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaCommunicationWhatsappSendFieldSchema
{
    /**
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'merchant', 'label' => 'Merchant ID'],
           // ['path' => 'created_at', 'label' => 'Webhook Created At'],
           // ['path' => 'data.notifiable', 'label' => 'Recipients (array)'],
            ['path' => 'data.notifiable.0', 'label' => 'Recipient Phone (first)'],
           // ['path' => 'data.type', 'label' => 'Message Type'],
            ['path' => 'data.content', 'label' => 'Message Content'],
            // ['path' => 'data.entity.type', 'label' => 'Entity Type'],
            // ['path' => 'data.entity.id', 'label' => 'Entity ID'],
            // ['path' => 'data.meta.customer_id', 'label' => 'Customer ID'],
            ['path' => 'data.meta.deliver_at', 'label' => 'Deliver At'],
        ];
    }
}
