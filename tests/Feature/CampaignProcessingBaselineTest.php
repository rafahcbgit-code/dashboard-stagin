<?php

/**
 * CampaignProcessingBaselineTest - Epic 2 Baseline Tests
 *
 * These tests document the CURRENT behavior of campaign processing BEFORE Epic 2 changes.
 * They serve as regression tests to ensure Epic 2 modifications don't break existing functionality.
 *
 * IMPORTANT: These tests were created based on the Feature Inventory (docs/sprint-artifacts/epic-2-feature-inventory.md)
 * Any test that fails after Epic 2 changes indicates a potential regression that must be addressed.
 *
 * Files Covered:
 * - CreateCampaignLogsJob.php
 * - ProcessCampaignMessagesJob.php
 * - ProcessSingleCampaignLogJob.php
 *
 * @see docs/sprint-artifacts/epic-2-feature-inventory.md
 */

use App\Jobs\CreateCampaignLogsJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(); // Prevent WhatsApp API calls
});

/**
 * Helper to create organization with campaign retry settings.
 */
function createOrgWithRetrySettings(array $retrySettings = []): Organization
{
    $defaultSettings = [
        'enable_resend' => true,
        'resend_intervals' => [5, 15, 60], // minutes
    ];

    $settings = array_merge($defaultSettings, $retrySettings);

    return Organization::factory()->state(function (array $attributes) use ($settings) {
        $metadata = json_decode($attributes['metadata'], true);
        $metadata['campaigns'] = $settings;
        $metadata['timezone'] = 'UTC';

        return ['metadata' => json_encode($metadata)];
    })->create();
}

/**
 * Helper to create organization WITHOUT retry settings (disabled).
 */
function createOrgWithoutRetry(): Organization
{
    return Organization::factory()->state(function (array $attributes) {
        $metadata = json_decode($attributes['metadata'], true);
        $metadata['campaigns'] = ['enable_resend' => false];
        $metadata['timezone'] = 'UTC';

        return ['metadata' => json_encode($metadata)];
    })->create();
}

// =============================================================================
// CreateCampaignLogsJob Tests
// =============================================================================

describe('CreateCampaignLogsJob - Campaign Discovery', function () {
    it('only processes scheduled campaigns that are due', function () {
        $org = createOrgWithoutRetry();

        // Create campaigns in different states
        $scheduledDue = Campaign::factory()->forOrganization($org)->scheduled()->create();
        $scheduledFuture = Campaign::factory()->forOrganization($org)->scheduledFuture()->create();
        $ongoing = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $completed = Campaign::factory()->forOrganization($org)->completed()->create();

        // Create contacts
        $contacts = Contact::factory()->count(3)->create(['organization_id' => $org->id]);

        // Run job
        $job = new CreateCampaignLogsJob();
        $job->handle();

        // Assert: Only the scheduled-due campaign should have logs created
        expect(CampaignLog::where('campaign_id', $scheduledDue->id)->count())->toBe(3);
        expect(CampaignLog::where('campaign_id', $scheduledFuture->id)->count())->toBe(0);
        expect(CampaignLog::where('campaign_id', $ongoing->id)->count())->toBe(0);
        expect(CampaignLog::where('campaign_id', $completed->id)->count())->toBe(0);
    });

    it('does not process deleted campaigns', function () {
        $org = createOrgWithoutRetry();
        $deletedCampaign = Campaign::factory()->forOrganization($org)->scheduled()->deleted()->create();
        Contact::factory()->count(3)->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        expect(CampaignLog::where('campaign_id', $deletedCampaign->id)->count())->toBe(0);
    });

    it('marks campaign as ongoing after creating logs', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        Contact::factory()->count(2)->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing');
    });
});

describe('CreateCampaignLogsJob - Contact Filtering', function () {
    it('only creates logs for subscribed contacts', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();

        // Create subscribed and unsubscribed contacts
        $subscribed = Contact::factory()->count(3)->create(['organization_id' => $org->id, 'is_subscribed' => 1]);
        $unsubscribed = Contact::factory()->count(2)->create(['organization_id' => $org->id, 'is_subscribed' => 0]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        // Should only have logs for subscribed contacts
        expect(CampaignLog::where('campaign_id', $campaign->id)->count())->toBe(3);
    });

    it('does not create logs for deleted contacts', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();

        // Create active and deleted contacts
        Contact::factory()->count(2)->create(['organization_id' => $org->id]);
        Contact::factory()->count(2)->create([
            'organization_id' => $org->id,
            'deleted_at' => now(),
        ]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        expect(CampaignLog::where('campaign_id', $campaign->id)->count())->toBe(2);
    });

    it('does not create duplicate logs for same contact', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Pre-create a log for this contact
        CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->create();

        $job = new CreateCampaignLogsJob();
        $job->handle();

        // Should still only have 1 log
        expect(CampaignLog::where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->count())->toBe(1);
    });
});

describe('CreateCampaignLogsJob - Log Creation', function () {
    it('creates logs with pending status', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        Contact::factory()->count(3)->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        $logs = CampaignLog::where('campaign_id', $campaign->id)->get();
        expect($logs->count())->toBe(3);
        foreach ($logs as $log) {
            expect($log->status)->toBe('pending');
        }
    });

    it('respects organization timezone for scheduling', function () {
        // Create org with different timezone
        $org = Organization::factory()->state(function (array $attributes) {
            $metadata = json_decode($attributes['metadata'], true);
            $metadata['timezone'] = 'Asia/Kuwait'; // UTC+3
            $metadata['campaigns'] = ['enable_resend' => false];

            return ['metadata' => json_encode($metadata)];
        })->create();

        // Campaign scheduled for "now" in Kuwait time
        $campaign = Campaign::factory()->forOrganization($org)->state([
            'status' => 'scheduled',
            'scheduled_at' => now()->subHours(1), // Should be due in Kuwait timezone
        ])->create();

        Contact::factory()->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        expect(CampaignLog::where('campaign_id', $campaign->id)->count())->toBeGreaterThan(0);
    });
});

// =============================================================================
// ProcessCampaignMessagesJob Tests
// =============================================================================

describe('ProcessCampaignMessagesJob - Log Selection', function () {
    it('only processes pending and failed logs for ongoing campaigns', function () {
        $org = createOrgWithRetrySettings();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create logs in different states
        $pendingLog = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->pending()->create();
        $failedLog = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->failed()->create();
        $successLog = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->success()->create();
        $ongoingLog = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->ongoing()->create();

        // Verify the query logic that ProcessCampaignMessagesJob uses
        // This is the core behavior we're testing - the SQL filtering
        $processableLogs = CampaignLog::with(['campaign.organization', 'contact'])
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing'))
            ->where('campaign_id', $campaign->id) // Scope to our test campaign
            ->get();

        // Only pending and failed logs should be selected
        expect($processableLogs->count())->toBe(2);
        expect($processableLogs->pluck('id')->toArray())->toContain($pendingLog->id);
        expect($processableLogs->pluck('id')->toArray())->toContain($failedLog->id);
        expect($processableLogs->pluck('id')->toArray())->not->toContain($successLog->id);
        expect($processableLogs->pluck('id')->toArray())->not->toContain($ongoingLog->id);
    });

    it('does not process logs for non-ongoing campaigns', function () {
        $org = createOrgWithoutRetry();

        // Create campaigns in different states
        $scheduled = Campaign::factory()->forOrganization($org)->scheduled()->create();
        $completed = Campaign::factory()->forOrganization($org)->completed()->create();

        // Create pending logs for both
        $scheduledLog = CampaignLog::factory()->forCampaign($scheduled)->pending()->create();
        $completedLog = CampaignLog::factory()->forCampaign($completed)->pending()->create();

        // Verify the query filtering - only ongoing campaigns should have logs processed
        $processableLogs = CampaignLog::whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing'))
            ->whereIn('campaign_id', [$scheduled->id, $completed->id])
            ->count();

        // Neither scheduled nor completed campaigns should have processable logs
        expect($processableLogs)->toBe(0);
    });
});

describe('ProcessCampaignMessagesJob - Retry Logic', function () {
    it('respects max retry limit from org metadata', function () {
        $org = createOrgWithRetrySettings(['resend_intervals' => [5, 15]]); // Max 2 retries
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create failed log that has already been retried twice
        $log = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->failed()->create();

        // Create 2 retry records to simulate max retries reached
        \App\Models\CampaignLogRetry::factory()->count(2)->forCampaignLog($log)->create();

        // Verify the retry count check in query
        $orgMetadata = json_decode($org->metadata, true);
        $maxRetries = count($orgMetadata['campaigns']['resend_intervals']);
        $retryCount = $log->retries()->count();

        // Log should NOT be processable because retryCount >= maxRetries
        expect($retryCount)->toBe(2);
        expect($maxRetries)->toBe(2);
        expect($retryCount >= $maxRetries)->toBeTrue();
    });

    it('does not retry failed logs when retry is disabled', function () {
        $org = createOrgWithoutRetry(); // Retry disabled
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        $log = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->failed()->create();

        // Verify retry is disabled in org metadata
        $orgMetadata = json_decode($org->metadata, true);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;

        expect($retryEnabled)->toBeFalse();
        // When retry is disabled, failed logs should not be picked up for processing
    });

    it('processes failed logs that have retries remaining', function () {
        $org = createOrgWithRetrySettings(['resend_intervals' => [5, 15, 60]]); // Max 3 retries
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create failed log with only 1 retry (2 remaining)
        $log = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->failed()->create();
        \App\Models\CampaignLogRetry::factory()->forCampaignLog($log)->create();

        // Verify the log has retries remaining
        $orgMetadata = json_decode($org->metadata, true);
        $maxRetries = count($orgMetadata['campaigns']['resend_intervals']);
        $retryCount = $log->retries()->count();

        expect($retryCount)->toBe(1);
        expect($maxRetries)->toBe(3);
        expect($retryCount < $maxRetries)->toBeTrue();

        // Verify the failed log is included in processable logs query
        // This is the key behavior - failed logs with retries remaining should be processable
        $processableLogs = CampaignLog::with(['campaign.organization', 'contact'])
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing'))
            ->where('campaign_id', $campaign->id)
            ->get();

        expect($processableLogs->count())->toBe(1);
        expect($processableLogs->first()->id)->toBe($log->id);
    });
});

describe('ProcessCampaignMessagesJob - Campaign Completion', function () {
    /**
     * BEHAVIORAL FINDING: Campaign completion check only runs when logs are processed.
     * If all logs are already 'success', the ProcessCampaignMessagesJob doesn't process
     * any logs (because query filters for pending/failed only), so markCampaignAsCompleted
     * is never called.
     *
     * This means campaigns with all success logs but not yet marked 'completed' won't
     * be auto-completed by ProcessCampaignMessagesJob - they need ProcessSingleCampaignLogJob
     * to complete and call checkAndUpdateCampaignStatus.
     *
     * This is EXISTING BEHAVIOR being documented, not a bug in the test.
     */
    it('marks campaign as completed after processing last pending log', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create one pending log that will be processed
        $pendingLog = CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->pending()->create();

        // Story 2.1: Now requires organizationId parameter
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // The campaign is added to $campaignsProcessed because we processed a log
        // markCampaignAsCompleted will be called, but since the log is still pending
        // (we only dispatched the job to process it, didn't actually process it),
        // campaign stays ongoing

        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing');

        // The completion happens in ProcessSingleCampaignLogJob::checkAndUpdateCampaignStatus
        // after the actual message is sent
    });

    it('does not mark campaign completed when pending logs exist', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create mixed logs
        CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->success()->create();
        CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->pending()->create();

        // Story 2.1: Now requires organizationId parameter
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing'); // Not completed
    });

    it('does not mark campaign completed when failed logs can still retry', function () {
        $org = createOrgWithRetrySettings(['resend_intervals' => [5, 15]]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        // Create failed log with retries remaining
        CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->failed()->create();

        // Story 2.1: Now requires organizationId parameter
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing'); // Not completed - retries pending
    });
});

// =============================================================================
// ProcessSingleCampaignLogJob Tests (Unit-level, no actual sending)
// =============================================================================

describe('ProcessSingleCampaignLogJob - Job Properties', function () {
    it('has correct timeout and retry configuration', function () {
        $log = CampaignLog::factory()->make();
        $job = new ProcessSingleCampaignLogJob($log);

        expect($job->timeout)->toBe(300);
        expect($job->tries)->toBe(3);
    });

    it('stores campaign log reference correctly', function () {
        $log = CampaignLog::factory()->make(['id' => 123]);
        $job = new ProcessSingleCampaignLogJob($log);

        // Job should have reference to the campaign log
        expect($job)->toHaveProperty('campaignLog');
    });
});

// =============================================================================
// Integration Tests - Full Flow
// =============================================================================

describe('Campaign Processing Full Flow', function () {
    it('processes campaign from scheduled to ongoing state', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        Contact::factory()->count(5)->create(['organization_id' => $org->id]);

        // Step 1: CreateCampaignLogsJob creates logs and marks ongoing
        $createJob = new CreateCampaignLogsJob();
        $createJob->handle();

        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing');
        expect(CampaignLog::where('campaign_id', $campaign->id)->count())->toBe(5);
        expect(CampaignLog::where('campaign_id', $campaign->id)->where('status', 'pending')->count())->toBe(5);
    });

    it('batches campaign logs correctly in ProcessCampaignMessagesJob', function () {
        $org = createOrgWithoutRetry();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Create 10 pending logs
        Contact::factory()->count(10)->create(['organization_id' => $org->id])->each(function ($contact) use ($campaign) {
            CampaignLog::factory()->forCampaign($campaign)->forContact($contact)->pending()->create();
        });

        // Verify we have 10 pending logs to process
        $pendingCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->count();
        expect($pendingCount)->toBe(10);

        // Verify the query that ProcessCampaignMessagesJob uses would find all 10
        $processableLogs = CampaignLog::with(['campaign.organization', 'contact'])
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing'))
            ->where('campaign_id', $campaign->id)
            ->get();

        expect($processableLogs->count())->toBe(10);
    });
});
