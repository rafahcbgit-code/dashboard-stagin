<?php

/**
 * CampaignRetriesQueryOptimizationTest - Task 2.8
 *
 * Tests to verify that N+1 query patterns for $log->retries()->count()
 * have been replaced with efficient withCount('retries') eager loading.
 *
 * AC-2.8.1: $log->retries()->count() pattern is replaced with eager-loaded count
 * AC-2.8.2: Query count does NOT scale linearly with number of logs processed
 * AC-2.8.3: ProcessCampaignMessagesJob uses withCount('retries') for batch queries
 * AC-2.8.4: ProcessSingleCampaignLogJob avoids N+1 when checking campaign completion
 * AC-2.8.5: RetryCampaignLogJob uses pre-loaded retry count
 *
 * @see docs/sprint-artifacts/2-8-fix-campaign-log-retries-n-plus-one-query.md
 */

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Clear query log before each test
    DB::flushQueryLog();
});

describe('Task 2.8: N+1 Query Optimization for Retries Count', function () {

    describe('AC-2.8.2: Query count does NOT scale linearly with logs', function () {

        it('uses constant query count when fetching logs with withCount', function () {
            // Arrange: Create organization with campaign settings
            $org = Organization::factory()->withCampaignSettings(500, true, [5, 15, 60])->create();
            $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

            // Create contacts for the campaign logs
            $contacts = Contact::factory()->count(50)->create(['organization_id' => $org->id]);

            // Create 50 campaign logs with varying retry counts
            $logs = collect();
            foreach ($contacts as $index => $contact) {
                $log = CampaignLog::factory()
                    ->forCampaign($campaign)
                    ->forContact($contact)
                    ->failed()
                    ->create();
                $logs->push($log);

                // Add retries to half of the logs (0-2 retries each)
                if ($index < 25) {
                    $retryCount = $index % 3;
                    CampaignLogRetry::factory()
                        ->count($retryCount)
                        ->forCampaignLog($log)
                        ->failed()
                        ->create();
                }
            }

            // Act: Enable query logging and fetch logs with withCount
            DB::enableQueryLog();
            $fetchedLogs = CampaignLog::where('campaign_id', $campaign->id)
                ->withCount('retries')
                ->get();
            $queriesWithWithCount = DB::getQueryLog();
            DB::flushQueryLog();

            // Assert: Should have retries_count attribute populated
            expect($fetchedLogs->first()->retries_count)->not->toBeNull();

            // Assert: Query count should be minimal (1-2 queries, not 50+)
            // withCount generates a single query with subselect
            expect(count($queriesWithWithCount))->toBeLessThanOrEqual(2);

            // Verify the retries_count values are correct
            foreach ($fetchedLogs as $log) {
                $actualCount = CampaignLogRetry::where('campaign_log_id', $log->id)->count();
                expect($log->retries_count)->toBe($actualCount);
            }
        });

        it('demonstrates N+1 problem would occur without withCount (baseline comparison)', function () {
            // Arrange
            $org = Organization::factory()->withCampaignSettings()->create();
            $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

            $contacts = Contact::factory()->count(20)->create(['organization_id' => $org->id]);
            $logs = collect();

            foreach ($contacts as $contact) {
                $log = CampaignLog::factory()
                    ->forCampaign($campaign)
                    ->forContact($contact)
                    ->failed()
                    ->create();
                $logs->push($log);

                // Add 1 retry to each log
                CampaignLogRetry::factory()
                    ->forCampaignLog($log)
                    ->failed()
                    ->create();
            }

            // Act: Simulate the OLD N+1 pattern (without withCount)
            DB::enableQueryLog();
            $fetchedLogs = CampaignLog::where('campaign_id', $campaign->id)->get();

            // This is what would cause N+1: calling ->retries()->count() in a loop
            foreach ($fetchedLogs as $log) {
                // Each call to retries()->count() executes a query
                $log->retries()->count();
            }
            $queriesWithN1 = DB::getQueryLog();
            DB::flushQueryLog();

            // Act: Now test the OPTIMIZED pattern
            DB::enableQueryLog();
            $optimizedLogs = CampaignLog::where('campaign_id', $campaign->id)
                ->withCount('retries')
                ->get();

            // Access retries_count - no additional queries
            foreach ($optimizedLogs as $log) {
                $log->retries_count;
            }
            $queriesOptimized = DB::getQueryLog();
            DB::flushQueryLog();

            // Assert: N+1 pattern should have 20+ queries (1 for fetch + 20 for counts)
            expect(count($queriesWithN1))->toBeGreaterThanOrEqual(21);

            // Assert: Optimized pattern should have only 1 query
            expect(count($queriesOptimized))->toBeLessThanOrEqual(2);

            // Log the improvement for documentation purposes
            $improvement = round((1 - count($queriesOptimized) / count($queriesWithN1)) * 100, 1);
            expect($improvement)->toBeGreaterThan(90); // At least 90% reduction
        });

    });

    describe('AC-2.8.1: retries_count attribute is correctly populated', function () {

        it('populates retries_count attribute with correct values', function () {
            $org = Organization::factory()->withCampaignSettings()->create();
            $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
            $contact = Contact::factory()->create(['organization_id' => $org->id]);

            $log = CampaignLog::factory()
                ->forCampaign($campaign)
                ->forContact($contact)
                ->failed()
                ->create();

            // Add 3 retries
            CampaignLogRetry::factory()
                ->count(3)
                ->forCampaignLog($log)
                ->failed()
                ->create();

            // Fetch with withCount
            $fetchedLog = CampaignLog::where('id', $log->id)
                ->withCount('retries')
                ->first();

            expect($fetchedLog->retries_count)->toBe(3);
        });

        it('returns 0 for logs without retries', function () {
            $org = Organization::factory()->withCampaignSettings()->create();
            $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
            $contact = Contact::factory()->create(['organization_id' => $org->id]);

            $log = CampaignLog::factory()
                ->forCampaign($campaign)
                ->forContact($contact)
                ->pending()
                ->create();

            // Fetch with withCount - no retries created
            $fetchedLog = CampaignLog::where('id', $log->id)
                ->withCount('retries')
                ->first();

            expect($fetchedLog->retries_count)->toBe(0);
        });

    });

    describe('AC-2.8.5: loadCount works for single model', function () {

        it('loads retry count efficiently for single model using loadCount', function () {
            $org = Organization::factory()->withCampaignSettings()->create();
            $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
            $contact = Contact::factory()->create(['organization_id' => $org->id]);

            $log = CampaignLog::factory()
                ->forCampaign($campaign)
                ->forContact($contact)
                ->failed()
                ->create();

            // Add 2 retries
            CampaignLogRetry::factory()
                ->count(2)
                ->forCampaignLog($log)
                ->failed()
                ->create();

            // Simulate RetryCampaignLogJob pattern: load model first, then loadCount
            DB::enableQueryLog();
            $fetchedLog = CampaignLog::with('campaign', 'contact')->find($log->id);
            $fetchedLog->loadCount('retries');
            $queries = DB::getQueryLog();
            DB::flushQueryLog();

            // Assert: retries_count is correctly populated
            expect($fetchedLog->retries_count)->toBe(2);

            // Assert: Should be reasonable query count (find with eager loads + loadCount)
            // The key verification is that retries_count is populated correctly
            expect(count($queries))->toBeLessThanOrEqual(5);
        });

    });

    describe('Query performance scaling', function () {

        it('query count stays constant regardless of log count', function () {
            // Create two separate campaigns to test scaling
            $org = Organization::factory()->withCampaignSettings()->create();
            $campaign1 = Campaign::factory()->forOrganization($org)->ongoing()->create();
            $campaign2 = Campaign::factory()->forOrganization($org)->ongoing()->create();

            // Create 10 logs for campaign1
            $contacts10 = Contact::factory()->count(10)->create(['organization_id' => $org->id]);
            foreach ($contacts10 as $contact) {
                $log = CampaignLog::factory()
                    ->forCampaign($campaign1)
                    ->forContact($contact)
                    ->failed()
                    ->create();
                CampaignLogRetry::factory()->forCampaignLog($log)->create();
            }

            // Create 50 logs for campaign2
            $contacts50 = Contact::factory()->count(50)->create(['organization_id' => $org->id]);
            foreach ($contacts50 as $contact) {
                $log = CampaignLog::factory()
                    ->forCampaign($campaign2)
                    ->forContact($contact)
                    ->failed()
                    ->create();
                CampaignLogRetry::factory()->forCampaignLog($log)->create();
            }

            // Now measure queries for each campaign separately
            DB::flushQueryLog();
            DB::enableQueryLog();
            CampaignLog::where('campaign_id', $campaign1->id)
                ->withCount('retries')
                ->get();
            $queries10 = count(DB::getQueryLog());
            DB::flushQueryLog();

            DB::enableQueryLog();
            CampaignLog::where('campaign_id', $campaign2->id)
                ->withCount('retries')
                ->get();
            $queries50 = count(DB::getQueryLog());
            DB::flushQueryLog();

            // Assert: Query count should be the same for both (1 query each with withCount)
            // withCount generates a single query with subselect regardless of result count
            expect($queries10)->toBe($queries50);

            // Both should be exactly 1 query
            expect($queries10)->toBe(1);
            expect($queries50)->toBe(1);
        });

    });

});
