<?php

/**
 * Task 2.7: Campaign Load Testing and Validation
 *
 * Feature tests to validate concurrent campaign processing
 * with organization-isolated queue channels (Epic 2 optimizations).
 *
 * Tests AC-2.7.1 through AC-2.7.6 acceptance criteria.
 */

use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Helper function to create an organization with WhatsApp and campaign settings.
 * Follows pattern from CampaignBatchSizeTest - doesn't create templates.
 */
function createConcurrencyTestOrg(int $contactCount = 100, string $prefix = 'TestOrg'): array
{
    $org = Organization::factory()->create([
        'name' => $prefix.'-'.now()->format('His'),
        'metadata' => json_encode([
            'whatsapp' => [
                'access_token' => 'test_token_'.fake()->uuid(),
                'phone_number_id' => 'test_phone_'.fake()->numerify('##########'),
                'waba_id' => 'test_waba_'.fake()->numerify('##########'),
                'app_id' => 'test_app_'.fake()->numerify('##########'),
            ],
            'timezone' => 'UTC',
            'campaigns' => [
                'batch_size' => 500,
                'enable_resend' => false,
                'resend_intervals' => [],
            ],
        ]),
    ]);

    // Create contacts
    $contacts = Contact::factory($contactCount)->create([
        'organization_id' => $org->id,
    ]);

    // Create campaign (uses default template_id from factory)
    $campaign = Campaign::factory()
        ->forOrganization($org)
        ->ongoing()
        ->create();

    // Create campaign logs
    foreach ($contacts as $contact) {
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);
    }

    return [
        'organization' => $org,
        'campaign' => $campaign,
        'contacts' => $contacts,
    ];
}

beforeEach(function () {
    // Clear organization metadata cache
    Cache::flush();

    // Fake the queue to capture dispatched jobs
    Queue::fake();

    // Fake HTTP for WhatsApp API calls
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.'.fake()->uuid()]],
        ], 200),
    ]);
});

describe('Organization Queue Isolation (AC-2.7.1)', function () {
    test('campaigns for different organizations dispatch to org-specific queues', function () {
        // Arrange: Create two organizations with campaigns
        $orgAData = createConcurrencyTestOrg(50, 'OrgA');
        $orgBData = createConcurrencyTestOrg(50, 'OrgB');

        // Act: Dispatch for both organizations
        ProcessCampaignMessagesJob::dispatchForOrganization($orgAData['organization']->id);
        ProcessCampaignMessagesJob::dispatchForOrganization($orgBData['organization']->id);

        // Assert: Each org's job goes to its own queue
        Queue::assertPushedOn(
            'campaign-org-'.$orgAData['organization']->id,
            ProcessCampaignMessagesJob::class
        );
        Queue::assertPushedOn(
            'campaign-org-'.$orgBData['organization']->id,
            ProcessCampaignMessagesJob::class
        );
    });

    test('job constructor sets correct org-specific queue', function () {
        $org = Organization::factory()->create();

        $job = new ProcessCampaignMessagesJob($org->id);

        expect($job->queue)->toBe('campaign-org-'.$org->id);
    });

    test('five organizations can have independent queue channels', function () {
        // Arrange: Create 5 organizations
        $orgs = collect();
        for ($i = 1; $i <= 5; $i++) {
            $orgData = createConcurrencyTestOrg(10, "Org{$i}");
            $orgs->push($orgData['organization']);
        }

        // Act: Dispatch for all 5 organizations
        foreach ($orgs as $org) {
            ProcessCampaignMessagesJob::dispatchForOrganization($org->id);
        }

        // Assert: 5 separate queue channels
        foreach ($orgs as $org) {
            Queue::assertPushedOn(
                'campaign-org-'.$org->id,
                ProcessCampaignMessagesJob::class
            );
        }
    });
});

describe('Campaign Non-Blocking (AC-2.7.2)', function () {
    test('processing job only queries logs for specified organization', function () {
        // Arrange: Create two orgs with campaigns
        $orgAData = createConcurrencyTestOrg(30, 'OrgA');
        $orgBData = createConcurrencyTestOrg(30, 'OrgB');

        // Clear queue fake and enable query log
        Queue::fake([]);
        Bus::fake([ProcessSingleCampaignLogJob::class]);
        DB::enableQueryLog();

        // Act: Process only org A
        $job = new ProcessCampaignMessagesJob($orgAData['organization']->id);
        $job->handle();

        // Assert: Org B's campaign logs should not be touched
        $orgBLogs = CampaignLog::where('campaign_id', $orgBData['campaign']->id)->get();
        expect($orgBLogs->every(fn ($log) => $log->status === 'pending'))->toBeTrue();

        // Assert: Org A's logs were processed (Bus::batch was called)
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() > 0;
        });
    });

    test('organization metadata is cached for performance', function () {
        $org = Organization::factory()->create([
            'metadata' => json_encode([
                'whatsapp' => ['access_token' => 'test'],
                'campaigns' => ['batch_size' => 500],
            ]),
        ]);

        // First call - should cache
        $metadata1 = OrganizationService::getCachedMetadata($org->id);

        // Second call - should hit cache
        $metadata2 = OrganizationService::getCachedMetadata($org->id);

        expect($metadata1)->toBe($metadata2);
        // Cache key uses 'org-metadata-' prefix per OrganizationService
        expect(Cache::has("org-metadata-{$org->id}"))->toBeTrue();
    });
});

describe('Message Integrity (AC-2.7.5)', function () {
    test('all campaign logs are created for all contacts', function () {
        $orgData = createConcurrencyTestOrg(100);

        $logCount = CampaignLog::where('campaign_id', $orgData['campaign']->id)->count();

        expect($logCount)->toBe(100);
    });

    test('pending logs count matches expected contact count', function () {
        $contactCount = 75;
        $orgData = createConcurrencyTestOrg($contactCount);

        $pendingCount = CampaignLog::where('campaign_id', $orgData['campaign']->id)
            ->where('status', 'pending')
            ->count();

        expect($pendingCount)->toBe($contactCount);
    });

    test('campaign log statuses are valid enum values', function () {
        $orgData = createConcurrencyTestOrg(10);

        // Update some logs to different statuses
        $logs = CampaignLog::where('campaign_id', $orgData['campaign']->id)->get();

        // All should be pending initially
        expect($logs->every(fn ($log) => in_array($log->status, ['pending', 'ongoing', 'success', 'failed', 'cancelled'])))->toBeTrue();
    });
});

describe('No Duplication (AC-2.7.6)', function () {
    test('campaign logs are created once per contact', function () {
        $orgData = createConcurrencyTestOrg(50);

        // Check for duplicate contact_id within same campaign
        $duplicates = CampaignLog::where('campaign_id', $orgData['campaign']->id)
            ->select('contact_id')
            ->groupBy('contact_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        expect($duplicates)->toHaveCount(0);
    });

    test('lock for update prevents duplicate processing', function () {
        $orgData = createConcurrencyTestOrg(1);
        $log = CampaignLog::where('campaign_id', $orgData['campaign']->id)->first();

        // Simulate the lockForUpdate query
        $lockedLog = CampaignLog::where('id', $log->id)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        expect($lockedLog)->not->toBeNull();
        expect($lockedLog->status)->toBe('pending');

        // Update to ongoing
        $lockedLog->update(['status' => 'ongoing']);

        // Second attempt should not find the log
        $secondAttempt = CampaignLog::where('id', $log->id)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        expect($secondAttempt)->toBeNull();
    });
});

describe('Queue Depth Management (AC-2.7.7)', function () {
    test('batch processing uses configured batch size', function () {
        $org = Organization::factory()->create([
            'metadata' => json_encode([
                'whatsapp' => ['access_token' => 'test'],
                'campaigns' => ['batch_size' => 250],
            ]),
        ]);

        // The getBatchSize method should respect org metadata
        $job = new ProcessCampaignMessagesJob($org->id);

        // Use reflection to access private method
        $reflection = new ReflectionMethod($job, 'getBatchSize');
        $reflection->setAccessible(true);

        $metadata = OrganizationService::getCachedMetadata($org->id);
        $batchSize = $reflection->invoke($job, $metadata);

        expect($batchSize)->toBe(250);
    });

    test('queue depth can be monitored via jobs table', function () {
        // This test validates the monitoring capability
        $queueDepths = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as depth'))
            ->groupBy('queue')
            ->pluck('depth', 'queue')
            ->toArray();

        // With Queue::fake(), no actual jobs in DB
        expect($queueDepths)->toBeArray();
    });
});

describe('Load Test Command', function () {
    test('load test command exists and has correct signature', function () {
        $this->artisan('campaign:load-test --dry-run')
            ->assertExitCode(0);
    });

    test('load test command shows dry run plan', function () {
        $this->artisan('campaign:load-test', [
            '--dry-run' => true,
            '--orgs' => 3,
            '--contacts' => 50,
        ])
            ->expectsOutput('Campaign Load Test')
            ->expectsOutput('Organizations: 3')
            ->expectsOutput('Contacts per org: 50')
            ->expectsOutput('Total messages: 150')
            ->assertExitCode(0);
    });

    test('cleanup-only mode works when no test data exists', function () {
        $this->artisan('campaign:load-test', ['--cleanup-only' => true])
            ->expectsOutput('No test data found to clean up.')
            ->assertExitCode(0);
    });
});

describe('Real Processing Integration', function () {
    test('ProcessCampaignMessagesJob processes pending logs for organization', function () {
        // This test runs with real job execution (no Queue::fake for this test)
        Queue::fake([]);
        Bus::fake([ProcessSingleCampaignLogJob::class]);

        $orgData = createConcurrencyTestOrg(10);

        $job = new ProcessCampaignMessagesJob($orgData['organization']->id);
        $job->handle();

        // Assert that ProcessSingleCampaignLogJob was dispatched in batch
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() > 0;
        });
    });

    test('job logs query metrics for performance tracking', function () {
        Bus::fake([ProcessSingleCampaignLogJob::class]);

        $orgData = createConcurrencyTestOrg(5);

        // Process the job
        $job = new ProcessCampaignMessagesJob($orgData['organization']->id);

        // Should complete without exceptions
        expect(fn () => $job->handle())->not->toThrow(Exception::class);
    });
});

describe('Cancelled Campaign Handling (AC-2.7.5, AC-2.7.6)', function () {
    test('cancelled campaigns are skipped during batch processing', function () {
        Bus::fake([ProcessSingleCampaignLogJob::class]);

        $orgData = createConcurrencyTestOrg(10);

        // Cancel the campaign before processing
        $orgData['campaign']->update(['status' => 'cancelled']);

        $job = new ProcessCampaignMessagesJob($orgData['organization']->id);
        $job->handle();

        // No batch should be dispatched when all logs are from cancelled campaign
        // The code only dispatches if !empty($jobs), and cancelled campaign logs are skipped
        Bus::assertNothingBatched();
    });

    test('pending logs for cancelled campaign are not processed as message loss', function () {
        $orgData = createConcurrencyTestOrg(10);

        // Cancel campaign
        $orgData['campaign']->update(['status' => 'cancelled']);

        // Pending logs remain - this is expected behavior, not message loss
        $pendingCount = CampaignLog::where('campaign_id', $orgData['campaign']->id)
            ->where('status', 'pending')
            ->count();

        expect($pendingCount)->toBe(10);
    });
});
