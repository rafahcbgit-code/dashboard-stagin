<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Models\Organization;
use App\Models\SallaAppInstallation;
use App\Models\SallaStore;
use App\Models\SallaWebhookDelivery;
use App\Models\Team;
use App\Models\User;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $storeName = data_get($data, 'app_name') ?? null;
        $organization = $this->ensureOrganizationForMerchant($merchantId, $delivery->organization_id, $storeName);
        $organizationId = $organization->id;

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

        $store = $this->ensureStoreRecord($merchantId, $organizationId, $storeName);

        SallaAppInstallation::updateOrCreate(
            [
                'merchant_id' => $merchantId,
                'salla_installation_id' => $installationId,
            ],
            [
                'salla_store_id' => $store->id,
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

        $delivery->update([
            'salla_store_id' => $store->id,
            'organization_id' => $organizationId,
        ]);

        $this->syncDeliveries($merchantId, $store);

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

    private function ensureOrganizationForMerchant(string $merchantId, ?int $existingOrganizationId, ?string $storeName): Organization
    {
        if ($existingOrganizationId) {
            $organization = Organization::find($existingOrganizationId);
            if ($organization) {
                return $organization;
            }
        }

        $store = SallaStore::where('merchant_id', $merchantId)
            ->whereNotNull('organization_id')
            ->first();

        if ($store?->organization_id) {
            $organization = Organization::find($store->organization_id);
            if ($organization) {
                return $organization;
            }
        }

        $creator = $this->resolveOrganizationCreator();
        $name = $storeName ?? "Salla Store {$merchantId}";
        $identifier = 'salla-' . $merchantId . '-' . Str::random(6);

        $organization = Organization::create([
            'identifier' => $identifier,
            'name' => $name,
            'created_by' => $creator->id,
            'metadata' => json_encode(['merchant_id' => $merchantId, 'source' => 'salla.app_installed']),
        ]);

        Team::create([
            'organization_id' => $organization->id,
            'user_id' => $creator->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return $organization;
    }

    private function resolveOrganizationCreator(): User
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if ($admin) {
            return $admin;
        }

        $user = User::orderBy('id')->first();
        if ($user) {
            return $user;
        }

        throw new \RuntimeException('No user available to assign as organization creator.');
    }

    private function ensureStoreRecord(string $merchantId, int $organizationId, ?string $storeName): SallaStore
    {
        $store = SallaStore::firstOrNew(['merchant_id' => $merchantId]);
        $store->organization_id = $organizationId;
        $store->merchant_name = $storeName ?? ($store->merchant_name ?? "Salla Store {$merchantId}");
        if (!$store->access_token) {
            $store->access_token = '';
        }
        if (!$store->refresh_token) {
            $store->refresh_token = '';
        }
        if (!$store->expires_at) {
            $store->expires_at = now();
        }
        $store->save();

        return $store;
    }

    private function syncDeliveries(string $merchantId, SallaStore $store): void
    {
        SallaWebhookDelivery::where('merchant_id', $merchantId)
            ->where(function ($query) {
                $query->whereNull('salla_store_id')->orWhereNull('organization_id');
            })
            ->update([
                'salla_store_id' => $store->id,
                'organization_id' => $store->organization_id,
            ]);
    }
}
