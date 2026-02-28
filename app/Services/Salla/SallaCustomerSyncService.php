<?php

namespace App\Services\Salla;

use App\Models\SallaStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Jobs\Salla\ProcessSallaCustomerChunk;

class SallaCustomerSyncService
{
    private const PER_PAGE    = 100;
    private const CHUNK_SIZE  = 50;      // Number of customers per processed chunk
    private const RATE_LIMIT_US = 500_000; // 0.5 second delay between API calls

    public function sync(
        int $organizationId,
        string $dateFrom,
        string $dateTo,
        ?string $tagName = null,
        ?string $groupName = null
    ): array {

        // Get latest Salla store for this organization
        $store = SallaStore::where('organization_id', $organizationId)
            ->latest()
            ->first();

        if (!$store) {
            throw new RuntimeException('missing_salla_store_for_organization');
        }

        // Build Salla customers endpoint
        $endpoint = rtrim(config('salla.salla-api-url'), '/') . '/customers';

        $params = [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'page'      => 1,
            'per_page'  => self::PER_PAGE,
        ];

        // Optional filter by tag (keyword)
        if ($tagName) {
            $params['keyword'] = $tagName;
        }

        $summary = [
            'total_fetched'    => 0,
            'dispatched_jobs'  => 0,
            'failed_count'     => 0,
        ];

        $nextUrl = $endpoint;
        $buffer  = [];

        while ($nextUrl) {

            // Call Salla API
            $response = Http::withToken($store->access_token)
                ->acceptJson()
                ->timeout(30)
                ->retry(3, 1000)
                ->get($nextUrl, $params);

            // After first request, pagination URL already contains parameters
            $params = [];

            if ($response->failed()) {
                Log::error('Salla sync failed', [
                    'organization_id' => $organizationId,
                    'status'          => $response->status(),
                    'body'            => $response->body(),
                ]);
                throw new RuntimeException('salla_api_error');
            }

            $payload   = $response->json() ?? [];
            $customers = data_get($payload, 'data', []);

            // Stop if no customers returned
            if (empty($customers)) {
                break;
            }

            foreach ($customers as $customer) {

                $mobile     = data_get($customer, 'mobile');
                $mobileCode = data_get($customer, 'mobile_code');

                // Skip customers without mobile number
                if (!$mobile) {
                    $summary['failed_count']++;
                    continue;
                }

                // Keep only required fields to minimize memory usage
                $buffer[] = [
                    'phone'      => ($mobileCode ?? '') . $mobile,
                    'first_name' => data_get($customer, 'full_name'),
                    'email'      => data_get($customer, 'email'),
                ];

                $summary['total_fetched']++;

                // Process chunk immediately when it reaches defined size
                if (count($buffer) >= self::CHUNK_SIZE) {
                    (new ProcessSallaCustomerChunk(
                        $organizationId,
                        $buffer,
                        $groupName
                    ))->handle(); // Run synchronously (without queue)

                    $summary['dispatched_jobs']++;
                    $buffer = []; // Free memory immediately
                }
            }

            Log::info('Salla sync page fetched', [
                'organization_id' => $organizationId,
                'page'            => data_get($payload, 'meta.pagination.current_page'),
                'count'           => count($customers),
            ]);

            // Get next page URL from pagination metadata
            $nextUrl = data_get($payload, 'meta.pagination.next_page_url');

            // Small delay to avoid hitting API rate limits
            usleep(self::RATE_LIMIT_US);
        }

        // Process remaining customers in buffer
        if (!empty($buffer)) {
            (new ProcessSallaCustomerChunk(
                $organizationId,
                $buffer,
                $groupName
            ))->handle(); // Run synchronously

            $summary['dispatched_jobs']++;
        }

        Log::info('Salla customer sync completed', array_merge($summary, [
            'organization_id' => $organizationId,
        ]));

        return $summary;
    }
}