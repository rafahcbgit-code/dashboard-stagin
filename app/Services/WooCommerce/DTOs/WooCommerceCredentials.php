<?php

namespace App\Services\WooCommerce\DTOs;

use App\Http\Requests\StoreWooCommerceIntegration;

class WooCommerceCredentials
{
    public function __construct(
        public string $consumerKey,
        public string $consumerSecret,
        public string $domainLink
    ) {
    }

    public static function fromRequest(StoreWooCommerceIntegration $request): self
    {
        return new self(
            $request->input('consumer_key'),
            $request->input('consumer_secret'),
            $request->input('domain_link'),
        );
    }

    public function toArray(): array
    {
        return [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            'domain_link' => $this->domainLink,
        ];
    }
}
