<?php

namespace App\Services\Salla\Webhooks\Schemas;

class SallaCustomerOtpFieldSchema
{
    /**
     * Fields that are exposed when refreshing webhook inputs for `customer.otp.request`.
     *
     * @return array<int, array{path:string, label:string}>
     */
    public function fields(): array
    {
        return [
            ['path' => 'data.code', 'label' => 'OTP Code']
        ];
    }
}
