<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaCustomerLoginFieldSchema
{
    /**
     * Fixed (non-dynamic) list of commonly used fields for `customer.login`.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'event', 'label' => 'Event'],
            ['path' => 'merchant', 'label' => 'Merchant ID'],
            ['path' => 'created_at', 'label' => 'Webhook Created At'],

            ['path' => 'data.id', 'label' => 'Customer ID'],
            ['path' => 'data.full_name', 'label' => 'Full Name'],
            ['path' => 'data.first_name', 'label' => 'First Name'],
            ['path' => 'data.last_name', 'label' => 'Last Name'],
            ['path' => 'data.mobile_code', 'label' => 'Mobile Code'],
            ['path' => 'data.mobile', 'label' => 'Mobile (No Code)'],
            ['path' => 'data.email', 'label' => 'Email'],

            ['path' => 'data.urls.customer', 'label' => 'Customer Profile URL'],
            ['path' => 'data.urls.admin', 'label' => 'Admin Customer URL'],

            ['path' => 'data.country', 'label' => 'Country'],
            ['path' => 'data.country_code', 'label' => 'Country Code'],
            ['path' => 'data.city', 'label' => 'City'],
            ['path' => 'data.currency', 'label' => 'Currency'],
            ['path' => 'data.lang', 'label' => 'Language'],
            ['path' => 'data.type', 'label' => 'Type'],

            ['path' => 'data.source.device', 'label' => 'Device'],
            ['path' => 'data.source.user-agent', 'label' => 'User Agent'],
            ['path' => 'data.source.ip', 'label' => 'IP'],

            ['path' => 'data.is_notifications_enabled', 'label' => 'Notifications Enabled'],
        ];
    }
}

