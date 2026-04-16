<?php

namespace App\Services\WooCommerce\Repositories\Eloquent;

use App\Models\WooCommerceIntegration;
use App\Services\WooCommerce\Contracts\WooCommerceIntegrationRepositoryInterface;

class WooCommerceIntegrationRepository implements WooCommerceIntegrationRepositoryInterface
{
    public function getByOrganization(int $organizationId): ?WooCommerceIntegration
    {
        return WooCommerceIntegration::with('user')
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function createOrUpdate(int $organizationId, array $data): WooCommerceIntegration
    {
        return WooCommerceIntegration::updateOrCreate(
            ['organization_id' => $organizationId],
            $data
        );
    }
}
