<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Models\SallaAppInstallation;
use App\Models\SallaStore;
use App\Models\SallaWebhookDelivery;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AppInstalledHandler implements SallaWebhookEventHandlerInterface
{
    public function handle(SallaWebhookDelivery $delivery): void
    {
        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $data = data_get($payload, 'data') ?? data_get($payload, 'payload.data') ?? [];

        if (!is_array($data)) {
            Log::warning('Salla app.installed: invalid data payload', [
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        $merchantId = (string) ($delivery->merchant_id ?? '');
        if ($merchantId === '') {
            Log::warning('Salla app.installed: missing merchant_id', [
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        // Try to backfill store/org in case the delivery was stored without them.
        $store = null;
        if (!$delivery->salla_store_id || !$delivery->organization_id) {
            $store = SallaStore::where('merchant_id', $merchantId)->first();
        }

        $sallaStoreId = $delivery->salla_store_id ?? $store?->id;
        $organizationId = $delivery->organization_id ?? $store?->organization_id;

        $installationId = data_get($data, 'id');
        $installationId = is_numeric($installationId) ? (int) $installationId : null;
        if ($installationId === null) {
            Log::warning('Salla app.installed: missing installation id', [
                'delivery_id' => $delivery->id,
                'merchant_id' => $merchantId,
            ]);
            return;
        }

        $scopes = data_get($data, 'app_scopes');
        $scopes = is_array($scopes) ? array_values($scopes) : null;
        $scopesCount = is_array($scopes) ? count($scopes) : 0;

        $installationDate = $this->parseDateTime(data_get($data, 'installation_date'));
        $webhookCreatedAt = $this->parseDateTime(data_get($payload, 'created_at') ?? data_get($payload, 'payload.created_at'));

        SallaAppInstallation::updateOrCreate(
            [
                'merchant_id' => $merchantId,
                'salla_installation_id' => $installationId,
            ],
            [
                'salla_store_id' => $sallaStoreId,
                'organization_id' => $organizationId,
                'source_delivery_id' => $delivery->id,
                'app_name' => data_get($data, 'app_name'),
                'app_description' => data_get($data, 'app_description'),
                'app_type' => data_get($data, 'app_type'),
                'store_type' => data_get($data, 'store_type'),
                'app_scopes' => $scopes,
                'scopes_count' => $scopesCount,
                'installation_date' => $installationDate,
                'webhook_created_at' => $webhookCreatedAt,
                'last_seen_at' => now(),
                'payload' => $payload,
            ]
        );

        Log::info('Salla app.installed stored', [
            'delivery_id' => $delivery->id,
            'merchant_id' => $merchantId,
            'organization_id' => $organizationId,
            'installation_id' => $installationId,
            'app_name' => data_get($data, 'app_name'),
        ]);
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
