<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappNumberStatusService
{
    public const STATUS_CONNECTED = 'CONNECTED';

    public const STATUS_DISCONNECTED = 'DISCONNECTED';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    public function sync(Organization|int $organization, string $source = 'graph_api'): array
    {
        $organization = $organization instanceof Organization
            ? $organization
            : Organization::find($organization);

        if (! $organization) {
            return ['success' => false, 'skipped' => true, 'reason' => 'organization_not_found'];
        }

        $metadata = $this->decodeMetadata($organization->metadata);
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        $accessToken = $whatsapp['access_token'] ?? null;
        $phoneNumberId = $whatsapp['phone_number_id'] ?? null;

        if (! $accessToken || ! $phoneNumberId) {
            return ['success' => false, 'skipped' => true, 'reason' => 'missing_credentials'];
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->get(
                    'https://graph.facebook.com/'.config('graph.api_version').'/'.$phoneNumberId,
                    ['fields' => 'status']
                );

            $responseData = $response->json();
            $status = is_array($responseData) ? ($responseData['status'] ?? null) : null;

            if ($response->successful() && is_string($status) && $status !== '') {
                $this->persistVerifiedStatus($organization->id, $status, $source);

                Log::channel('whatsapp_number_status')->info('Meta phone number status API completed', [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name,
                    'waba_id' => $whatsapp['waba_id'] ?? null,
                    'phone_number_id' => $phoneNumberId,
                    'display_phone_number' => $whatsapp['display_phone_number'] ?? null,
                    'source' => $source,
                    'http_status' => $response->status(),
                    'number_status' => $status,
                    'success' => true,
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                ]);

                return ['success' => true, 'status' => $status];
            }

            $errorCode = data_get($responseData, 'error.code');
            $errorMessage = data_get($responseData, 'error.message', 'Meta did not return a phone number status.');
            $temporaryFailure = $response->serverError() || $response->status() === 429;
            $failedStatus = (int) $errorCode === 100
                ? self::STATUS_DISCONNECTED
                : self::STATUS_UNKNOWN;

            $this->persistFailedCheck(
                $organization->id,
                $source,
                $errorCode,
                $errorMessage,
                $temporaryFailure ? null : $failedStatus
            );
            $storedStatus = $this->getStoredStatus($organization->id);

            Log::channel('whatsapp_number_status')->warning('Meta phone number status API failed', [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'waba_id' => $whatsapp['waba_id'] ?? null,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $whatsapp['display_phone_number'] ?? null,
                'source' => $source,
                'http_status' => $response->status(),
                'number_status' => $storedStatus,
                'success' => false,
                'temporary_failure' => $temporaryFailure,
                'error_code' => $errorCode,
                'error' => $errorMessage,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return [
                'success' => false,
                'status' => $temporaryFailure ? null : $failedStatus,
                'error_code' => $errorCode,
                'error' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            $this->persistFailedCheck($organization->id, $source, null, $e->getMessage(), null);

            Log::channel('whatsapp_number_status')->error('Meta phone number status API exception', [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'waba_id' => $whatsapp['waba_id'] ?? null,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $whatsapp['display_phone_number'] ?? null,
                'source' => $source,
                'number_status' => $whatsapp['number_status'] ?? null,
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return ['success' => false, 'status' => null, 'error' => $e->getMessage()];
        }
    }

    private function persistVerifiedStatus(int $organizationId, string $status, string $source): void
    {
        $this->updateMetadata($organizationId, function (array &$whatsapp) use ($source, $status) {
            $this->rememberLastKnownStatus($whatsapp);
            $whatsapp['number_status'] = $status;
            $whatsapp['number_status_check_state'] = 'verified';
            $whatsapp['number_status_source'] = $source;
            $whatsapp['number_status_check_source'] = $source;
            $whatsapp['number_status_checked_at'] = now()->toIso8601String();
            unset(
                $whatsapp['number_status_event'],
                $whatsapp['number_status_error'],
                $whatsapp['number_status_error_code']
            );
        });
    }

    private function persistFailedCheck(
        int $organizationId,
        string $source,
        mixed $errorCode,
        string $errorMessage,
        ?string $failedStatus
    ): void {
        $this->updateMetadata($organizationId, function (array &$whatsapp) use (
            $source,
            $errorCode,
            $errorMessage,
            $failedStatus
        ) {
            if ($failedStatus !== null) {
                $this->rememberLastKnownStatus($whatsapp);
                $whatsapp['number_status'] = $failedStatus;
                $whatsapp['number_status_source'] = $source;
            }

            $whatsapp['number_status_check_state'] = 'failed';
            $whatsapp['number_status_check_source'] = $source;
            $whatsapp['number_status_checked_at'] = now()->toIso8601String();
            $whatsapp['number_status_error'] = mb_substr($errorMessage, 0, 500);

            if ($errorCode !== null) {
                $whatsapp['number_status_error_code'] = $errorCode;
            } else {
                unset($whatsapp['number_status_error_code']);
            }
        });
    }

    private function rememberLastKnownStatus(array &$whatsapp): void
    {
        $currentStatus = $whatsapp['number_status'] ?? null;

        if ($currentStatus && $currentStatus !== self::STATUS_UNKNOWN) {
            $whatsapp['number_status_last_known'] = $currentStatus;
        }
    }

    private function getStoredStatus(int $organizationId): ?string
    {
        $metadata = $this->decodeMetadata(Organization::find($organizationId)?->metadata);
        $status = data_get($metadata, 'whatsapp.number_status');

        return is_string($status) ? $status : null;
    }

    private function updateMetadata(int $organizationId, callable $callback): void
    {
        DB::transaction(function () use ($organizationId, $callback) {
            $organization = Organization::query()->lockForUpdate()->find($organizationId);

            if (! $organization) {
                return;
            }

            $metadata = $this->decodeMetadata($organization->metadata);
            $metadata['whatsapp'] = is_array($metadata['whatsapp'] ?? null)
                ? $metadata['whatsapp']
                : [];

            $callback($metadata['whatsapp']);

            $organization->metadata = json_encode($metadata);
            $organization->save();
        });
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        $decoded = json_decode($metadata ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
