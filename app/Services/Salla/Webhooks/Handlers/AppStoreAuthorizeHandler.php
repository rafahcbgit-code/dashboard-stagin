<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Models\Organization;
use App\Models\SallaStore;
use App\Models\SallaWebhookDelivery;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppStoreAuthorizeHandler implements SallaWebhookEventHandlerInterface
{
    public function handle(SallaWebhookDelivery $delivery): void
    {
        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $data = data_get($payload, 'data');

        if (!is_array($data)) {
            Log::warning('Salla app.store.authorize: missing data payload', [
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        $merchantId = (string) ($payload['merchant'] ?? $delivery->merchant_id ?? '');
        if ($merchantId === '') {
            Log::warning('Salla app.store.authorize: missing merchant id', [
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        $accessToken = data_get($data, 'access_token');
        $refreshToken = data_get($data, 'refresh_token');
        $expiresAt = $this->createCarbonFromTimestamp(data_get($data, 'expires'));
        $scopeValue = data_get($data, 'scope');
        $scopes = $this->normalizeScopes($scopeValue);

        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            Log::warning('Salla app.store.authorize: missing tokens', [
                'merchant_id' => $merchantId,
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        $storeInfo = $this->fetchUserInfo($accessToken);
        $storeName = data_get($storeInfo, 'data.merchant.name') ?? data_get($payload, 'data.app_name') ?? 'Salla Store ' . $merchantId;
        $ownerEmail = $this->extractOwnerEmail($storeInfo, $merchantId);
        $ownerMobile = $this->extractOwnerMobile($storeInfo, $merchantId);
        $ownerFullName = $this->extractOwnerName($storeInfo, $storeName);

        [$firstName, $lastName] = $this->splitFullName($ownerFullName);

        DB::transaction(function () use (
            $merchantId,
            $accessToken,
            $refreshToken,
            $expiresAt,
            $scopes,
            $storeName,
            $ownerEmail,
            $firstName,
            $lastName,
            $ownerMobile,
            $delivery
        ) {
            $existingStore = SallaStore::where('merchant_id', $merchantId)->first();
            $organizationId = $existingStore?->organization_id;

            $user = $this->ensureOwner($ownerEmail, $firstName, $lastName, $ownerMobile);
            $organization = $this->ensureOrganization($user, $storeName, $merchantId, $organizationId);
            $this->ensureTeam($user, $organization);
            $this->ensureSubscription($organization);
            $store = $this->persistStoreCredentials(
                $merchantId,
                $organization->id,
                $storeName,
                $accessToken,
                $refreshToken,
                $expiresAt,
                $scopes
            );
            $this->syncExistingDeliveries($merchantId, $store);
        });

        Log::info('Salla app.store.authorize: created organization for merchant', [
            'delivery_id' => $delivery->id,
            'merchant_id' => $merchantId,
            'store_name' => $storeName,
        ]);
    }

    private function fetchStoreInfo(string $accessToken): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->get('https://api.salla.dev/admin/v2/store/info');
        } catch (\Throwable $exception) {
            Log::warning('Salla app.store.authorize: failed to fetch store info', [
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Salla app.store.authorize: store info request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    private function fetchUserInfo(string $accessToken)
    {
        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->get('https://accounts.salla.sa/oauth2/user/info');

            if ($response->successful()) {
                Log::info('Salla user info response', [
                    'data' => $response->json(),
                ]);

                return $response->json();
            } else {
                Log::warning('Salla user info request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Salla app.store.authorize: failed to fetch user info', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ensureOwner(string $email, string $firstName, string $lastName, string $ownerMobile): User
    {
        $user = User::where('email', $email)->first();

        $plainPassword = Str::random(16);

        if (! $user) {
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'role' => 'user',
                'phone' => $ownerMobile,
                'code_verified' => 1,
                'password' => bcrypt($plainPassword),
            ]);

            return $user;
        }

        $user->update([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'code_verified' => 1,
        ]);

        return $user;
    }

    private function ensureOrganization(User $user, string $storeName, string $merchantId, ?int $existingOrganizationId): Organization
    {
        if ($existingOrganizationId) {
            $organization = Organization::find($existingOrganizationId);
            if ($organization) {
                
                return $organization;
            }
        }

        $identifier = null;

        return Organization::create([
            'identifier' => $identifier,
            'name' => $storeName,
            'created_by' => $user->id,
            'metadata' => null,
        ]);
    }

    private function ensureTeam(User $user, Organization $organization): void
    {
        Team::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => 'owner',
                'status' => 'active',
                'created_by' => $user->id,
            ]
        );
    }

    private function ensureSubscription(Organization $organization): void
    {
        if ($organization->subscription) {
            return;
        }

        $trialPeriod = (int) Setting::where('key', 'trial_period')->value('value');
        $status = $trialPeriod > 0 ? 'trial' : 'active';
        $validUntil = $trialPeriod > 0 ? now()->addDays($trialPeriod) : now();

        Subscription::create([
            'organization_id' => $organization->id,
            'status' => $status,
            'plan_id' => null,
            'start_date' => now(),
            'valid_until' => $validUntil,
        ]);
    }

    private function persistStoreCredentials(
        string $merchantId,
        int $organizationId,
        string $storeName,
        string $accessToken,
        string $refreshToken,
        Carbon $expiresAt,
        array $scopes
    ): SallaStore {
        SallaStore::updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'organization_id' => $organizationId,
                'merchant_name' => $storeName,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_token_expires_at' => now()->addDays(30),
                'refresh_token_used_at' => null,
                'last_refresh_at' => now(),
                'scopes' => $scopes,
                'token_version' => DB::raw('COALESCE(token_version, 0) + 1'),
                'is_revoked' => false,
                'revoked_at' => null,
                'revocation_reason' => null,
            ]
        );
        return SallaStore::where('merchant_id', $merchantId)->firstOrFail();
    }

    private function syncExistingDeliveries(string $merchantId, SallaStore $store): void
    {
        if (!$store->id) {
            return;
        }

        SallaWebhookDelivery::where('merchant_id', $merchantId)
            ->where(function ($query) {
                $query->whereNull('salla_store_id')->orWhereNull('organization_id');
            })
            ->update([
                'salla_store_id' => $store->id,
                'organization_id' => $store->organization_id,
            ]);
    }

    private function createCarbonFromTimestamp(mixed $value): Carbon
    {
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return now();
    }

    private function normalizeScopes(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = preg_split('/[\\s,]+/', trim($value));
        } else {
            $items = [];
        }

        $filtered = [];
        foreach ($items as $scope) {
            $scope = is_string($scope) ? trim($scope) : '';
            if ($scope !== '') {
                $filtered[] = $scope;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function extractOwnerEmail(?array $storeInfo, string $merchantId): string
    {
        $email = data_get($storeInfo, 'data.email');
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return "salla-merchant-{$merchantId}@chatberry.salla";
    }

    private function extractOwnerMobile(?array $storeInfo, string $merchantId): string
    {
        $mobile = data_get($storeInfo, 'data.mobile');
        if (is_string($mobile) && $mobile !== '') {
            return $mobile;
        }

        return "+966560000000";
    }

    private function extractOwnerName(?array $storeInfo, string $fallbackName): string
    {
        $name = data_get($storeInfo, 'data.owner.name') ?? data_get($storeInfo, 'data.user.full_name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $fallbackName;
    }

    private function splitFullName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return ['Salla', 'Merchant'];
        }

        $parts = preg_split('/\\s+/', $fullName);

        if (empty($parts)) {
            return ['Salla', 'Merchant'];
        }

        $first = array_shift($parts);
        $last = empty($parts) ? 'Merchant' : implode(' ', $parts);

        return [$first, $last];
    }
}
