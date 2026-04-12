<?php

namespace App\Services\Zid\Webhooks\Schemas;

class ZidCustomerLoginFieldSchema
{
    public function fields(): array
    {
        return [
            ['path' => 'id', 'label' => 'Customer ID'],
            ['path' => 'name', 'label' => 'Name'],
            ['path' => 'email', 'label' => 'Email'],
            ['path' => 'telephone', 'label' => 'Telephone'],
            ['path' => 'gender', 'label' => 'Gender'],
            ['path' => 'birth_date', 'label' => 'Birth Date'],
            ['path' => 'store_id', 'label' => 'Store ID'],
            ['path' => 'is_active', 'label' => 'Is Active'],
        ];
    }
}
