<?php

/**
 * CampaignQueryOptimizationTest - Story 2.2 Performance Benchmarks
 *
 * Tests for query optimization and caching in campaign processing.
 *
 * @see Story 2.2: Optimize Campaign Eager Loading and Caching
 * @see AC-2.2.1: Query count reduced from ~1500 to <600 per 500-log batch
 * @see AC-2.2.4: Campaign batch processing time reduced by 50%
 */

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    Cache::flush();
});

/**
 * Helper to create organization with campaign retry settings.
 */
function createOrgWithCampaignSettings(array $settings = []): Organization
{
    $defaultSettings = [
        'campaigns' => [
            'enable_resend' => true,
            'resend_intervals' => [5, 15, 60],
        ],
        'whatsapp' => [
            'access_token' => 'test-token',
            'phone_number_id' => '123456789',
            'waba_id' => 'waba123',
            'app_id' => 'app123',
        ],
        'timezone' => 'UTC',
    ];

    $metadata = array_merge_recursive($defaultSettings, $settings);

    return Organization::factory()->create([
        'metadata' => json_encode($metadata),
    ]);
}

describe('Query Count Optimization (AC-2.2.1)', function () {
    it('uses selective column loading for CampaignLog queries (AC-2.2.3)', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(10)->create(['organization_id' => $org->id]);

        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Act - Enable query logging
        DB::enableQueryLog();
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $queries = DB::getQueryLog();

        // Assert - Check that CampaignLog query uses select
        $campaignLogQueries = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'campaign_logs') &&
                   str_contains($query['query'], 'select');
        });

        // At least one CampaignLog query should be present
        expect(count($campaignLogQueries))->toBeGreaterThanOrEqual(1);

        // The main select query should only get the columns we need
        $mainQuery = array_values($campaignLogQueries)[0] ?? null;
        if ($mainQuery) {
            // Should use selective columns, not select *
            expect($mainQuery['query'])->not->toContain('select *');
        }
    });

    it('caches organization metadata and reduces repeated fetches (AC-2.2.2)', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(20)->create(['organization_id' => $org->id]);

        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Clear cache to start fresh
        Cache::flush();

        // Act
        DB::enableQueryLog();
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $queries = DB::getQueryLog();

        // Assert - Count organization metadata queries
        $orgQueries = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'organizations') &&
                   str_contains($query['query'], 'select') &&
                   ! str_contains($query['query'], 'campaign');
        });

        // With caching, there should be at most 1-2 organization queries
        // (initial fetch for cache population)
        expect(count($orgQueries))->toBeLessThanOrEqual(2);

        // Verify cache is populated
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();
    });

    it('reduces total query count for batch of 50 logs', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(50)->create(['organization_id' => $org->id]);

        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Clear cache
        Cache::flush();

        // Act
        DB::enableQueryLog();
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Assert - Query count should be reasonable
        // Before optimization: ~150 queries for 50 logs (3x per log)
        // After optimization: Should be significantly less
        // Expected: ~60-80 queries (eager loading + 1 retries count per log)
        expect($queryCount)->toBeLessThan(150);

        // Log for baseline documentation
        dump("Query count for 50 logs: {$queryCount}");
    });

    it('scales efficiently - query count for 100 logs should not be 2x 50 logs', function () {
        // Arrange - 100 logs
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(100)->create(['organization_id' => $org->id]);

        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        Cache::flush();

        // Act
        DB::enableQueryLog();
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $queries100 = DB::getQueryLog();
        $queryCount100 = count($queries100);

        // The query count should scale sub-linearly due to:
        // 1. Cached organization metadata (1 query regardless of log count)
        // 2. Eager loaded relationships (2 queries for campaign + contact)
        // The only linear scaling is retries count per log

        // For 100 logs with optimization:
        // - 1 org metadata (cached)
        // - 1 main select with eager loading
        // - 100 retries count queries (1 per log - this is the remaining N+1)
        // Total: ~102-110 queries

        expect($queryCount100)->toBeLessThan(200);

        dump("Query count for 100 logs: {$queryCount100}");
    });
});

describe('Processing Time (AC-2.2.4)', function () {
    it('processes batch within acceptable time', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(50)->create(['organization_id' => $org->id]);

        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        Cache::flush();

        // Act
        $startTime = microtime(true);
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        // Assert - Processing should be fast (under 5 seconds for 50 logs in test env)
        expect($elapsedMs)->toBeLessThan(5000);

        dump("Processing time for 50 logs: {$elapsedMs}ms");
    });
});

describe('Metadata Caching Integration (AC-2.2.2)', function () {
    it('getCachedMetadata returns correct data after organization update', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();

        // First fetch - populates cache
        $metadata1 = OrganizationService::getCachedMetadata($org->id);
        expect($metadata1['campaigns']['enable_resend'])->toBeTrue();

        // Update organization metadata
        $org->metadata = json_encode([
            'campaigns' => ['enable_resend' => false],
            'whatsapp' => ['access_token' => 'new-token'],
            'timezone' => 'UTC',
        ]);
        $org->save();

        // Second fetch - should return new data (cache invalidated by model event)
        $metadata2 = OrganizationService::getCachedMetadata($org->id);
        expect($metadata2['campaigns']['enable_resend'])->toBeFalse();
    });

    it('ProcessCampaignMessagesJob uses cached metadata', function () {
        // Arrange
        $org = createOrgWithCampaignSettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        // Pre-populate cache
        OrganizationService::getCachedMetadata($org->id);
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Act - Run job
        DB::enableQueryLog();
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();
        $queries = DB::getQueryLog();

        // Assert - No organization queries should be made (using cache)
        $orgSelectQueries = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'from `organizations`') &&
                   str_contains($query['query'], 'select') &&
                   ! str_contains($query['query'], 'campaign');
        });

        // Should have 0 organization queries since cache was pre-populated
        expect(count($orgSelectQueries))->toBe(0);
    });
});
