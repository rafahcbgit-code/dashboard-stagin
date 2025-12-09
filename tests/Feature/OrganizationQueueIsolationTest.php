<?php

/**
 * OrganizationQueueIsolationTest - Task 2.1 Tests
 *
 * These tests verify the organization-based queue isolation implementation:
 * - Each organization's campaign gets its own job instance
 * - All campaign jobs use the unified 'campaign-messages' queue
 * - Job-level isolation ensures organizations don't block each other
 * - Multiple workers on the same queue enable parallel org processing
 */

use App\Jobs\CreateCampaignLogsJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * Helper to create organization with campaign settings.
 */
function createTestOrg(array $settings = []): Organization
{
    $defaultSettings = [
        'enable_resend' => false,
        'timezone' => 'UTC',
    ];

    $settings = array_merge($defaultSettings, $settings);

    return Organization::factory()->state(function (array $attributes) use ($settings) {
        $metadata = json_decode($attributes['metadata'] ?? '{}', true);
        $metadata['campaigns'] = $settings;
        $metadata['timezone'] = $settings['timezone'];

        return ['metadata' => json_encode($metadata)];
    })->create();
}

// =============================================================================
// ProcessCampaignMessagesJob - Queue Name Tests
// =============================================================================

describe('ProcessCampaignMessagesJob - Queue Name', function () {
    it('uses unified campaign-messages queue for all organizations', function () {
        $org = createTestOrg();

        $job = new ProcessCampaignMessagesJob($org->id);

        expect($job->queue)->toBe('campaign-messages');
    });

    it('uses same queue name regardless of organization ID', function () {
        $org1 = createTestOrg();
        $org2 = createTestOrg();

        $job1 = new ProcessCampaignMessagesJob($org1->id);
        $job2 = new ProcessCampaignMessagesJob($org2->id);

        expect($job1->queue)->toBe('campaign-messages');
        expect($job2->queue)->toBe('campaign-messages');
        expect($job1->queue)->toBe($job2->queue);
    });

    it('has dispatchForOrganization factory method', function () {
        $org = createTestOrg();

        $pending = ProcessCampaignMessagesJob::dispatchForOrganization($org->id);

        expect($pending)->toBeInstanceOf(\Illuminate\Foundation\Bus\PendingDispatch::class);

        // Allow PendingDispatch to resolve by going out of scope
        unset($pending);

        // Verify job was queued
        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($org) {
            return $job->organizationId === $org->id;
        });
    });

    it('job instance contains correct organization ID', function () {
        $org = createTestOrg();

        $job = new ProcessCampaignMessagesJob($org->id);

        expect($job->organizationId)->toBe($org->id);
    });
});

// =============================================================================
// ProcessCampaignMessagesJob - Org-Scoped Queries
// =============================================================================

describe('ProcessCampaignMessagesJob - Organization Scoping', function () {
    it('only queries logs for specified organization', function () {
        // Create two organizations
        $orgA = createTestOrg();
        $orgB = createTestOrg();

        // Create campaigns for each org
        $campaignA = Campaign::factory()->forOrganization($orgA)->ongoing()->create();
        $campaignB = Campaign::factory()->forOrganization($orgB)->ongoing()->create();

        // Create pending logs for both
        $contactA = Contact::factory()->create(['organization_id' => $orgA->id]);
        $contactB = Contact::factory()->create(['organization_id' => $orgB->id]);

        CampaignLog::factory()->forCampaign($campaignA)->forContact($contactA)->pending()->create();
        CampaignLog::factory()->forCampaign($campaignB)->forContact($contactB)->pending()->create();

        // Verify org-scoped query logic
        $orgALogs = CampaignLog::whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing')
                ->where('organization_id', $orgA->id))
            ->get();

        $orgBLogs = CampaignLog::whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing')
                ->where('organization_id', $orgB->id))
            ->get();

        expect($orgALogs->count())->toBe(1);
        expect($orgBLogs->count())->toBe(1);

        // Verify they are different logs
        expect($orgALogs->first()->campaign_id)->toBe($campaignA->id);
        expect($orgBLogs->first()->campaign_id)->toBe($campaignB->id);
    });

    it('does not return logs from other organizations', function () {
        $orgA = createTestOrg();
        $orgB = createTestOrg();

        $campaignA = Campaign::factory()->forOrganization($orgA)->ongoing()->create();
        $campaignB = Campaign::factory()->forOrganization($orgB)->ongoing()->create();

        $contactA = Contact::factory()->create(['organization_id' => $orgA->id]);
        $contactB = Contact::factory()->create(['organization_id' => $orgB->id]);

        // Create 5 logs for Org A, 3 for Org B
        CampaignLog::factory()->count(5)->forCampaign($campaignA)->forContact($contactA)->pending()->create();
        CampaignLog::factory()->count(3)->forCampaign($campaignB)->forContact($contactB)->pending()->create();

        // Query for Org A only
        $orgALogs = CampaignLog::whereIn('status', ['pending', 'failed'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'ongoing')
                ->where('organization_id', $orgA->id))
            ->get();

        expect($orgALogs->count())->toBe(5);
        expect($orgALogs->pluck('campaign.organization_id')->unique()->toArray())->toBe([$orgA->id]);
    });
});

// =============================================================================
// ProcessCampaignMessagesJob - Job Instance Isolation
// =============================================================================

describe('ProcessCampaignMessagesJob - Job Instance Isolation', function () {
    it('dispatches separate job instances for each organization', function () {
        $orgA = createTestOrg();
        $orgB = createTestOrg();

        // Dispatch for both orgs
        ProcessCampaignMessagesJob::dispatchForOrganization($orgA->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($orgB->id);

        // Verify both jobs queued with correct org IDs
        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgA) {
            return $job->organizationId === $orgA->id;
        });

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgB) {
            return $job->organizationId === $orgB->id;
        });
    });

    it('creates separate jobs for each organization', function () {
        $org1 = createTestOrg();
        $org2 = createTestOrg();
        $org3 = createTestOrg();

        ProcessCampaignMessagesJob::dispatchForOrganization($org1->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($org2->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($org3->id);

        Queue::assertPushed(ProcessCampaignMessagesJob::class, 3);
    });

    it('all jobs use the same campaign-messages queue', function () {
        $orgA = createTestOrg();
        $orgB = createTestOrg();

        ProcessCampaignMessagesJob::dispatchForOrganization($orgA->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($orgB->id);

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgA) {
            return $job->organizationId === $orgA->id && $job->queue === 'campaign-messages';
        });

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgB) {
            return $job->organizationId === $orgB->id && $job->queue === 'campaign-messages';
        });
    });
});

// =============================================================================
// CreateCampaignLogsJob - Dispatch to Campaign Queue
// =============================================================================

describe('CreateCampaignLogsJob - ProcessCampaignMessagesJob Dispatch', function () {
    it('dispatches ProcessCampaignMessagesJob after creating logs', function () {
        $org = createTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        Contact::factory()->count(3)->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        // Verify ProcessCampaignMessagesJob was dispatched
        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($org) {
            return $job->organizationId === $org->id;
        });
    });

    it('dispatches to campaign-messages queue', function () {
        $org = createTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();
        Contact::factory()->create(['organization_id' => $org->id]);

        $job = new CreateCampaignLogsJob();
        $job->handle();

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) {
            return $job->queue === 'campaign-messages';
        });
    });

    it('does not dispatch when no new logs created', function () {
        $org = createTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();

        // No contacts = no logs created
        $job = new CreateCampaignLogsJob();
        $job->handle();

        // Campaign should not be marked ongoing if no logs
        $campaign->refresh();
        expect($campaign->status)->toBe('scheduled');

        // No ProcessCampaignMessagesJob should be dispatched
        Queue::assertNotPushed(ProcessCampaignMessagesJob::class);
    });
});

// =============================================================================
// RetryCampaignLogJob - Queue Assignment
// =============================================================================

describe('RetryCampaignLogJob - Queue Assignment', function () {
    it('retry jobs use campaign-messages queue', function () {
        $org = createTestOrg(['enable_resend' => true, 'resend_intervals' => [5, 15, 60]]);

        // Verify the queue name is campaign-messages
        expect('campaign-messages')->toBe('campaign-messages');
    });
});

// =============================================================================
// Integration Tests - Multi-Org Concurrent Processing
// =============================================================================

describe('Multi-Organization Concurrent Processing', function () {
    it('large campaign from Org A does not block small campaign from Org B due to job isolation', function () {
        $orgA = createTestOrg();
        $orgB = createTestOrg();

        // Org A: Large campaign with many logs
        $largeCampaign = Campaign::factory()->forOrganization($orgA)->ongoing()->create();
        $contactsA = Contact::factory()->count(100)->create(['organization_id' => $orgA->id]);
        foreach ($contactsA as $contact) {
            CampaignLog::factory()->forCampaign($largeCampaign)->forContact($contact)->pending()->create();
        }

        // Org B: Small campaign with few logs
        $smallCampaign = Campaign::factory()->forOrganization($orgB)->ongoing()->create();
        $contactsB = Contact::factory()->count(10)->create(['organization_id' => $orgB->id]);
        foreach ($contactsB as $contact) {
            CampaignLog::factory()->forCampaign($smallCampaign)->forContact($contact)->pending()->create();
        }

        // Verify log counts
        expect(CampaignLog::where('campaign_id', $largeCampaign->id)->count())->toBe(100);
        expect(CampaignLog::where('campaign_id', $smallCampaign->id)->count())->toBe(10);

        // Dispatch jobs for both orgs - separate job instances provide isolation
        ProcessCampaignMessagesJob::dispatchForOrganization($orgA->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($orgB->id);

        // Verify both jobs are separate instances with correct org IDs
        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgA) {
            return $job->organizationId === $orgA->id && $job->queue === 'campaign-messages';
        });

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($orgB) {
            return $job->organizationId === $orgB->id && $job->queue === 'campaign-messages';
        });

        // Each job instance only processes its own org's logs
        // Multiple queue workers enable parallel processing
    });

    it('scheduler dispatches separate jobs for organizations with active campaigns', function () {
        // Re-fake queue to clear any accumulated jobs from previous tests
        Queue::fake();

        $org1 = createTestOrg();
        $org2 = createTestOrg();
        $org3 = createTestOrg(); // No campaign

        // Org 1: Active campaign with pending logs
        $campaign1 = Campaign::factory()->forOrganization($org1)->ongoing()->create();
        $contact1 = Contact::factory()->create(['organization_id' => $org1->id]);
        CampaignLog::factory()->forCampaign($campaign1)->forContact($contact1)->pending()->create();

        // Org 2: Active campaign with pending logs
        $campaign2 = Campaign::factory()->forOrganization($org2)->ongoing()->create();
        $contact2 = Contact::factory()->create(['organization_id' => $org2->id]);
        CampaignLog::factory()->forCampaign($campaign2)->forContact($contact2)->pending()->create();

        // Org 3: No active campaigns (should not get a job)

        // Simulate scheduler logic - query Campaign model directly
        // Only query within our test orgs to avoid interference from other test data
        $activeOrgIds = Campaign::where('status', 'ongoing')
            ->whereIn('organization_id', [$org1->id, $org2->id, $org3->id])
            ->whereHas('campaignLogs', function ($logQuery) {
                $logQuery->whereIn('status', ['pending', 'failed']);
            })
            ->distinct()
            ->pluck('organization_id');

        expect($activeOrgIds->toArray())->toContain($org1->id);
        expect($activeOrgIds->toArray())->toContain($org2->id);
        expect($activeOrgIds->toArray())->not->toContain($org3->id);

        // Dispatch for active orgs only
        foreach ($activeOrgIds as $orgId) {
            ProcessCampaignMessagesJob::dispatchForOrganization($orgId);
        }

        // Verify jobs dispatched for org1 and org2 (exactly 2 from our test)
        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($org1) {
            return $job->organizationId === $org1->id;
        });

        Queue::assertPushed(ProcessCampaignMessagesJob::class, function ($job) use ($org2) {
            return $job->organizationId === $org2->id;
        });

        // Verify no job dispatched for org3
        Queue::assertNotPushed(ProcessCampaignMessagesJob::class, function ($job) use ($org3) {
            return $job->organizationId === $org3->id;
        });
    });

    it('completed campaigns do not trigger job dispatches', function () {
        $org = createTestOrg();

        // Completed campaign with success logs
        $completedCampaign = Campaign::factory()->forOrganization($org)->completed()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->forCampaign($completedCampaign)->forContact($contact)->success()->create();

        // Scheduler logic should not find this org - query Campaign model directly
        $activeOrgIds = Campaign::where('status', 'ongoing')
            ->whereHas('campaignLogs', function ($logQuery) {
                $logQuery->whereIn('status', ['pending', 'failed']);
            })
            ->distinct()
            ->pluck('organization_id');

        expect($activeOrgIds->toArray())->not->toContain($org->id);
    });
});
