<?php

namespace App\Services\WooCommerce\Contracts;

use App\Models\WooCommerceIntegration;

interface WooCommerceIntegrationRepositoryInterface
{
    public function getByOrganization(int $organizationId): ?WooCommerceIntegration;

    public function createOrUpdate(int $organizationId, array $data): WooCommerceIntegration;
}
