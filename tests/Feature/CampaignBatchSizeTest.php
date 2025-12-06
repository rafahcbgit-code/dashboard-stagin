<?php

/**
 * CampaignBatchSizeTest - Story 2.3 Configurable Batch Sizes
 *
 * Tests for configurable batch size in campaign processing.
 *
 * @see Story 2.3: Implement Configurable Batch Sizes
 * @see AC-2.3.1: Default batch size is 500 contacts per batch from config/campaigns.php
 * @see AC-2.3.2: Batch size configurable via config/campaigns.php
 * @see AC-2.3.3: Batch size can be overridden per organization via metadata.campaigns.batch_size
 * @see AC-2.3.4: Batch progress tracked accurately (logs processed count)
 */

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    Cache::flush();
});

/**
 * Helper to create organization with campaign settings including batch_size.
 */
function createOrgWithBatchSettings(array $campaignSettings = []): Organization
{
    $defaultSettings = [
        'campaigns' => array_merge([
            'enable_resend' => true,
            'resend_intervals' => [5, 15, 60],
        ], $campaignSettings),
        'whatsapp' => [
            'access_token' => 'test-token',
            'phone_number_id' => '123456789',
            'waba_id' => 'waba123',
            'app_id' => 'app123',
        ],
        'timezone' => 'UTC',
    ];

    return Organization::factory()->create([
        'metadata' => json_encode($defaultSettings),
    ]);
}

describe('Config File (AC-2.3.1, AC-2.3.2)', function () {
    it('has default batch_size of 500 in config', function () {
        // Assert - Default config value
        expect(config('campaigns.batch_size'))->toBe(500);
    });

    it('has max_batch_size safety limit in config', function () {
        // Assert - Max batch size is configured
        expect(config('campaigns.max_batch_size'))->toBe(1000);
    });

    it('batch_size can be changed via config', function () {
        // Arrange - Override config
        Config::set('campaigns.batch_size', 250);

        // Assert
        expect(config('campaigns.batch_size'))->toBe(250);
    });

    it('supports environment variable override', function () {
        // The config file supports env('CAMPAIGN_BATCH_SIZE', 500)
        // This test verifies the config structure exists
        $configFile = file_get_contents(config_path('campaigns.php'));

        expect($configFile)->toContain("env('CAMPAIGN_BATCH_SIZE'");
        expect($configFile)->toContain("env('CAMPAIGN_MAX_BATCH_SIZE'");
    });
});

describe('Organization Override (AC-2.3.3)', function () {
    it('uses org metadata batch_size when set', function () {
        // Arrange - Org with custom batch size
        $org = createOrgWithBatchSettings(['batch_size' => 300]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Create enough logs to require multiple batches at 300 size
        $contacts = Contact::factory()->count(5)->create(['organization_id' => $org->id]);
        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Act - Capture log to verify batch size used
        $loggedBatchSize = null;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$loggedBatchSize) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $loggedBatchSize = $context['configured_batch_size'] ?? null;
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Assert - Should use org's batch size
        expect($loggedBatchSize)->toBe(300);
    });

    it('falls back to config when org metadata not set', function () {
        // Arrange - Org without batch_size in metadata
        $org = createOrgWithBatchSettings([]); // No batch_size
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        // Act
        $loggedBatchSize = null;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$loggedBatchSize) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $loggedBatchSize = $context['configured_batch_size'] ?? null;
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Assert - Should use config default (500)
        expect($loggedBatchSize)->toBe(500);
    });

    it('caps org batch_size at max_batch_size', function () {
        // Arrange - Org with batch size exceeding max
        $org = createOrgWithBatchSettings(['batch_size' => 2000]); // Exceeds 1000 max
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        // Act
        $loggedBatchSize = null;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$loggedBatchSize) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $loggedBatchSize = $context['configured_batch_size'] ?? null;
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Assert - Should be capped at max (1000)
        expect($loggedBatchSize)->toBe(1000);
    });

    it('caps config batch_size at max_batch_size', function () {
        // Arrange - Config exceeds max
        Config::set('campaigns.batch_size', 1500);
        Config::set('campaigns.max_batch_size', 1000);

        $org = createOrgWithBatchSettings([]); // No org override
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        // Act
        $loggedBatchSize = null;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$loggedBatchSize) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $loggedBatchSize = $context['configured_batch_size'] ?? null;
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Assert - Should be capped at max (1000)
        expect($loggedBatchSize)->toBe(1000);
    });
});

describe('Batch Progress Logging (AC-2.3.4)', function () {
    it('logs batch start with batch number and size', function () {
        // Arrange
        $org = createOrgWithBatchSettings([]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(5)->create(['organization_id' => $org->id]);
        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Act
        $batchStartLogged = false;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use ($org, &$batchStartLogged) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $batchStartLogged = true;
                    expect($context)->toHaveKey('organization_id');
                    expect($context)->toHaveKey('batch_number');
                    expect($context)->toHaveKey('batch_size');
                    expect($context)->toHaveKey('configured_batch_size');
                    expect($context['organization_id'])->toBe($org->id);
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        expect($batchStartLogged)->toBeTrue();
    });

    it('logs total processed count after all batches', function () {
        // Arrange
        $org = createOrgWithBatchSettings([]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(10)->create(['organization_id' => $org->id]);
        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Act
        $completedLogged = false;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use ($org, &$completedLogged) {
                if ($message === 'ProcessCampaignMessagesJob batches completed') {
                    $completedLogged = true;
                    expect($context)->toHaveKey('organization_id');
                    expect($context)->toHaveKey('total_batches');
                    expect($context)->toHaveKey('total_processed');
                    expect($context)->toHaveKey('batch_size_used');
                    expect($context['organization_id'])->toBe($org->id);
                    expect($context['total_processed'])->toBe(10);
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        expect($completedLogged)->toBeTrue();
    });

    it('includes organization_id in all log context', function () {
        // Arrange
        $org = createOrgWithBatchSettings([]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        // Act
        $allHaveOrgId = true;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use ($org, &$allHaveOrgId) {
                if (str_contains($message, 'ProcessCampaignMessagesJob')) {
                    if (! isset($context['organization_id']) || $context['organization_id'] !== $org->id) {
                        $allHaveOrgId = false;
                    }
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        expect($allHaveOrgId)->toBeTrue();
    });
});

describe('Batch Size Integration', function () {
    it('processes correct number of logs per batch', function () {
        // Arrange - Small batch size to force multiple batches
        Config::set('campaigns.batch_size', 3);

        $org = createOrgWithBatchSettings([]);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contacts = Contact::factory()->count(7)->create(['organization_id' => $org->id]);
        foreach ($contacts as $contact) {
            CampaignLog::factory()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        // Act
        $batchSizes = [];
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$batchSizes) {
                if ($message === 'ProcessCampaignMessagesJob batch started') {
                    $batchSizes[] = $context['batch_size'];
                }

                return true;
            })
            ->andReturn(null);

        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Assert - Should have 3 batches: 3, 3, 1
        expect(count($batchSizes))->toBe(3);
        expect($batchSizes[0])->toBe(3);
        expect($batchSizes[1])->toBe(3);
        expect($batchSizes[2])->toBe(1);
    });
});
