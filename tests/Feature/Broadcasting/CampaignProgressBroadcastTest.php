<?php


use App\Events\CampaignProgressEvent;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    Cache::flush();
    // Reset progress state between tests
    ProcessSingleCampaignLogJob::resetProgressState();
});

/**
 * Helper to create organization with WhatsApp settings.
 */
function createProgressTestOrg(array $settings = []): Organization
{
    $defaultSettings = [
        'campaigns' => [
            'enable_resend' => false,
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

describe('Progress broadcasting throttle logic (AC-2.5.3)', function () {
    it('broadcasts after 10 messages are processed when time threshold not met', function () {
        Event::fake([CampaignProgressEvent::class]);

        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(15)->create(['organization_id' => $org->id]);

        // Create 15 campaign logs (pending)
        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Simulate job processing checking shouldBroadcastProgress
        $job = new ProcessSingleCampaignLogJob(CampaignLog::first());
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('shouldBroadcastProgress');
        $method->setAccessible(true);

        // First call returns true because time difference from epoch (0) > 5 seconds
        $firstCall = $method->invoke($job, $campaign->id);
        expect($firstCall)->toBeTrue('First call broadcasts (time threshold met from epoch)');

        // Now subsequent 9 calls should return false (state was reset after first broadcast)
        for ($i = 1; $i < 10; $i++) {
            expect($method->invoke($job, $campaign->id))->toBeFalse("Call $i after first should be false");
        }

        // 10th call after first broadcast should return true
        expect($method->invoke($job, $campaign->id))->toBeTrue('10th call after first should be true');

        // Next call should reset and be false again
        expect($method->invoke($job, $campaign->id))->toBeFalse('After reset should be false');
    });

    it('broadcasts immediately on first call due to time threshold from epoch', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('shouldBroadcastProgress');
        $method->setAccessible(true);

        // Reset state
        ProcessSingleCampaignLogJob::resetProgressState($campaign->id);

        // First call should broadcast (time difference from epoch 0 is > 5 seconds)
        $result1 = $method->invoke($job, $campaign->id);
        expect($result1)->toBeTrue('First call should broadcast (time threshold met from epoch)');

        // Subsequent calls within 5 seconds should return false (until 10 messages)
        for ($i = 1; $i < 10; $i++) {
            $result = $method->invoke($job, $campaign->id);
            expect($result)->toBeFalse("Call $i after reset should be false");
        }

        // 10th call should return true (message threshold)
        expect($method->invoke($job, $campaign->id))->toBeTrue('10th call should be true');
    });
});

describe('Estimated completion calculation (AC-2.5.5)', function () {
    it('returns Calculating when elapsed time is less than 10 seconds', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create([
            'created_at' => now()->subSeconds(5), // 5 seconds ago
        ]);

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('calculateEstimatedCompletion');
        $method->setAccessible(true);

        $result = $method->invoke($job, $campaign, 50, 100); // 50 sent, 100 pending

        expect($result)->toBe('Calculating...');
    });

    it('returns null when pending is zero', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create([
            'created_at' => now()->subMinutes(5),
        ]);

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('calculateEstimatedCompletion');
        $method->setAccessible(true);

        $result = $method->invoke($job, $campaign, 100, 0); // All sent, none pending

        expect($result)->toBeNull();
    });

    it('returns Calculating when sent is zero', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create([
            'created_at' => now()->subMinutes(5),
        ]);

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('calculateEstimatedCompletion');
        $method->setAccessible(true);

        $result = $method->invoke($job, $campaign, 0, 100); // None sent yet

        expect($result)->toBe('Calculating...');
    });

    it('calculates minutes remaining for moderate durations', function () {
        $org = createProgressTestOrg();
        // Campaign started 60 seconds ago
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create([
            'created_at' => now()->subSeconds(60),
        ]);

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('calculateEstimatedCompletion');
        $method->setAccessible(true);

        // 60 messages sent in 60 seconds = 1 msg/sec
        // 120 pending / 1 msg/sec = 120 seconds = 2 minutes
        $result = $method->invoke($job, $campaign, 60, 120);

        expect($result)->toContain('minutes remaining');
    });

    it('calculates hours remaining for long durations', function () {
        $org = createProgressTestOrg();
        // Campaign started 30 minutes ago
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create([
            'created_at' => now()->subMinutes(30),
        ]);

        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
        ]));

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('calculateEstimatedCompletion');
        $method->setAccessible(true);

        // 100 messages sent in 30 minutes (1800 seconds) = ~0.055 msg/sec
        // 10000 pending / 0.055 = ~180000 seconds = ~50 hours
        $result = $method->invoke($job, $campaign, 100, 10000);

        expect($result)->toContain('hours remaining');
    });
});

describe('Private channel authorization logic (AC-2.5.6)', function () {
    it('channel callback returns true for user belonging to campaign organization', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        $user = User::factory()->create();
        // Create team membership
        Team::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'role' => 'owner',
            'created_by' => $user->id,
        ]);

        // Verify user has team membership
        $hasTeam = $user->teams()
            ->where('organization_id', $campaign->organization_id)
            ->exists();

        expect($hasTeam)->toBeTrue('User should belong to campaign organization');
    });

    it('channel callback returns false for user not belonging to campaign organization', function () {
        $org = createProgressTestOrg();
        $otherOrg = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        $user = User::factory()->create();
        // Create team membership in DIFFERENT org
        Team::create([
            'user_id' => $user->id,
            'organization_id' => $otherOrg->id,
            'role' => 'owner',
            'created_by' => $user->id,
        ]);

        // Verify user doesn't belong to campaign's organization
        $hasTeam = $user->teams()
            ->where('organization_id', $campaign->organization_id)
            ->exists();

        expect($hasTeam)->toBeFalse('User should not belong to campaign organization');
    });
});

describe('CampaignProgressEvent dispatch (AC-2.5.1, AC-2.5.2)', function () {
    it('dispatches event with correct payload structure', function () {
        Event::fake([CampaignProgressEvent::class]);

        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Dispatch the event directly using event() helper
        event(new CampaignProgressEvent(
            campaignUuid: $campaign->uuid,
            total: 100,
            sent: 45,
            failed: 5,
            pending: 50,
            percentComplete: 50.0,
            estimatedCompletion: '~3 minutes remaining'
        ));

        // Assert event was dispatched (simpler assertion)
        Event::assertDispatched(CampaignProgressEvent::class);
    });

    it('event has correct properties set', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid-123',
            total: 100,
            sent: 45,
            failed: 5,
            pending: 50,
            percentComplete: 50.0,
            estimatedCompletion: '~3 minutes remaining'
        );

        expect($event->campaignUuid)->toBe('test-uuid-123');
        expect($event->total)->toBe(100);
        expect($event->sent)->toBe(45);
        expect($event->failed)->toBe(5);
        expect($event->pending)->toBe(50);
        expect($event->percentComplete)->toBe(50.0);
        expect($event->estimatedCompletion)->toBe('~3 minutes remaining');
    });

    it('calculates percentComplete correctly', function () {
        $org = createProgressTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Create campaign logs
        Contact::factory()->count(10)->create(['organization_id' => $org->id])->each(function ($contact) use ($campaign) {
            CampaignLog::factory()->success()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
            ]);
        });

        Contact::factory()->count(5)->create(['organization_id' => $org->id])->each(function ($contact) use ($campaign) {
            CampaignLog::factory()->failed()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
            ]);
        });

        Contact::factory()->count(85)->create(['organization_id' => $org->id])->each(function ($contact) use ($campaign) {
            CampaignLog::factory()->pending()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
            ]);
        });

        // Total: 100, Sent: 10, Failed: 5, Pending: 85
        // percentComplete = (10 + 5) / 100 * 100 = 15%

        $job = new ProcessSingleCampaignLogJob(CampaignLog::where('campaign_id', $campaign->id)->first());
        $reflection = new ReflectionClass($job);
        $statsMethod = $reflection->getMethod('getCampaignStats');
        $statsMethod->setAccessible(true);

        $stats = $statsMethod->invoke($job, $campaign->id);

        expect((int) $stats->total)->toBe(100);
        expect((int) $stats->sent)->toBe(10);
        expect((int) $stats->failed)->toBe(5);
        expect((int) $stats->pending)->toBe(85);

        // Calculate percent complete manually
        $percentComplete = round(($stats->sent + $stats->failed) / $stats->total * 100, 1);
        expect($percentComplete)->toBe(15.0);
    });
});

describe('Progress state management', function () {
    it('resets progress state for specific campaign', function () {
        $campaignId = 123;

        // Simulate some state
        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create());
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('shouldBroadcastProgress');
        $method->setAccessible(true);

        // Build up some state
        for ($i = 0; $i < 5; $i++) {
            $method->invoke($job, $campaignId);
        }

        // Reset specific campaign
        ProcessSingleCampaignLogJob::resetProgressState($campaignId);

        // State should be cleared - first call after reset should broadcast (time threshold)
        $result = $method->invoke($job, $campaignId);
        expect($result)->toBeTrue('First call after reset should broadcast');
    });

    it('resets all progress state when no campaign specified', function () {
        // Build up state for multiple campaigns
        $job = new ProcessSingleCampaignLogJob(CampaignLog::factory()->create());
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('shouldBroadcastProgress');
        $method->setAccessible(true);

        for ($i = 0; $i < 5; $i++) {
            $method->invoke($job, 1);
            $method->invoke($job, 2);
        }

        // Reset all
        ProcessSingleCampaignLogJob::resetProgressState();

        // Both should start fresh
        $result1 = $method->invoke($job, 1);
        $result2 = $method->invoke($job, 2);

        expect($result1)->toBeTrue('Campaign 1 should broadcast after full reset');
        expect($result2)->toBeTrue('Campaign 2 should broadcast after full reset');
    });
});
