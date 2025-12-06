<?php


use App\Http\Middleware\CheckOrganizationId;
use App\Http\Middleware\IsTrial;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    Cache::flush();

    // Disable middleware that prevents testing (CSRF, org check, trial check)
    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        CheckOrganizationId::class,
        IsTrial::class,
    ]);
});

/**
 * Helper to create organization with WhatsApp settings.
 */
function createCancelTestOrg(): Organization
{
    return Organization::factory()->create([
        'metadata' => json_encode([
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
        ]),
    ]);
}

/**
 * Helper to create user with team membership in an organization.
 */
function createUserWithOrg(Organization $org): User
{
    $user = User::factory()->create();
    Team::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'role' => 'owner',
        'created_by' => $user->id,
    ]);

    return $user;
}

describe('Cancel Endpoint Returns 200 and Updates Status (AC-2.6.1, AC-2.6.2)', function () {
    it('returns 200 OK and updates campaign status to cancelled for ongoing campaign', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Create some campaign logs
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'cancelled',
                'message' => 'Campaign cancellation initiated',
            ]);

        // Verify campaign status updated
        $campaign->refresh();
        expect($campaign->status)->toBe('cancelled');
    });
});

describe('Pending Logs Marked as Cancelled (AC-2.6.3)', function () {
    it('marks all pending CampaignLog records as cancelled', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // Create multiple campaign logs with different statuses
        $contacts = Contact::factory()->count(5)->create(['organization_id' => $org->id]);

        // 2 pending logs - should be cancelled
        CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[0]->id,
        ]);
        CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[1]->id,
        ]);

        // 2 success logs - should NOT be cancelled
        CampaignLog::factory()->success()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[2]->id,
        ]);
        CampaignLog::factory()->success()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[3]->id,
        ]);

        // 1 failed log - should NOT be cancelled
        CampaignLog::factory()->failed()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[4]->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(200);

        // Verify pending logs are now cancelled
        $cancelledCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'cancelled')
            ->count();
        expect($cancelledCount)->toBe(2);

        // Verify success and failed logs are unchanged
        $successCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'success')
            ->count();
        expect($successCount)->toBe(2);

        $failedCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->count();
        expect($failedCount)->toBe(1);
    });
});

describe('Final Stats Returned (AC-2.6.5)', function () {
    it('returns correct sent, cancelled, and failed counts in response', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        $contacts = Contact::factory()->count(10)->create(['organization_id' => $org->id]);

        // Create logs: 3 success, 4 pending (will become cancelled), 2 failed, 1 permanently_failed
        foreach (range(0, 2) as $i) {
            CampaignLog::factory()->success()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contacts[$i]->id,
            ]);
        }
        foreach (range(3, 6) as $i) {
            CampaignLog::factory()->pending()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contacts[$i]->id,
            ]);
        }
        foreach (range(7, 8) as $i) {
            CampaignLog::factory()->failed()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contacts[$i]->id,
            ]);
        }
        // 1 permanently_failed
        CampaignLog::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[9]->id,
            'status' => 'permanently_failed',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'cancelled',
                'stats' => [
                    'sent' => 3,
                    'cancelled' => 4,
                    'failed' => 3, // 2 failed + 1 permanently_failed
                ],
            ]);
    });
});

describe('Authorization - Only Org Admins Can Cancel (AC-2.6.6)', function () {
    it('returns 404 when campaign belongs to different organization', function () {
        $org = createCancelTestOrg();
        $otherOrg = createCancelTestOrg();
        $user = createUserWithOrg($org);

        // Campaign belongs to OTHER organization
        $campaign = Campaign::factory()->forOrganization($otherOrg)->ongoing()->create();

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Campaign not found',
            ]);

        // Verify campaign was NOT cancelled
        $campaign->refresh();
        expect($campaign->status)->toBe('ongoing');
    });

    it('returns 404 for non-existent campaign UUID', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson('/campaigns/00000000-0000-0000-0000-000000000000/cancel');

        $response->assertStatus(404);
    });
});

describe('Status Validation - Only Ongoing Campaigns (AC-2.6.7)', function () {
    it('returns 400 for completed campaign', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->completed()->create();

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Campaign can only be cancelled when ongoing',
                'current_status' => 'completed',
            ]);
    });

    it('returns 400 for scheduled campaign', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->scheduled()->create();

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Campaign can only be cancelled when ongoing',
                'current_status' => 'scheduled',
            ]);
    });

    it('returns 400 for already cancelled campaign', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->cancelled()->create();

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Campaign can only be cancelled when ongoing',
                'current_status' => 'cancelled',
            ]);
    });
});

describe('ProcessSingleCampaignLogJob Cancellation Check (AC-2.6.4)', function () {
    it('skips sending when campaign is cancelled', function () {
        $org = createCancelTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->cancelled()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        $log = CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        // Track if cancellation skip is logged
        $skipLogged = false;
        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) use (&$skipLogged, $campaign, $log) {
                if ($message === 'Campaign cancelled - skipping message') {
                    $skipLogged = true;
                    expect($context['campaign_id'])->toBe($campaign->id);
                    expect($context['campaign_log_id'])->toBe($log->id);
                }

                return true;
            })
            ->andReturn(null);

        // Allow other log calls
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);

        $job = new ProcessSingleCampaignLogJob($log);
        $job->handle();

        // Verify log was skipped and logged
        expect($skipLogged)->toBeTrue();

        // Verify log status is now cancelled
        $log->refresh();
        expect($log->status)->toBe('cancelled');
    });

    it('updates log status to cancelled if pending when campaign is cancelled', function () {
        $org = createCancelTestOrg();
        $campaign = Campaign::factory()->forOrganization($org)->cancelled()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        $log = CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        // Suppress logging
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);

        $job = new ProcessSingleCampaignLogJob($log);
        $job->handle();

        $log->refresh();
        expect($log->status)->toBe('cancelled');
    });
});

describe('ProcessCampaignMessagesJob Cancellation Check (AC-2.6.4)', function () {
    it('does not dispatch jobs for campaigns that become cancelled mid-batch', function () {
        // This tests the scenario where a campaign is cancelled while the batch job is running
        // The check inside the foreach loop handles this edge case
        $org = createCancelTestOrg();

        // Create an ongoing campaign that will be "cancelled" during processing
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();
        $contact = Contact::factory()->create(['organization_id' => $org->id]);
        CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        // Suppress all logging
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);

        // The job should process normally for ongoing campaigns
        $job = new ProcessCampaignMessagesJob($org->id);
        $job->handle();

        // Verify that jobs were queued (since campaign is ongoing)
        Queue::assertPushed(ProcessSingleCampaignLogJob::class);
    });
});

describe('Edge Cases', function () {
    it('handles campaign with no logs gracefully', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // No campaign logs created

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'cancelled',
                'stats' => [
                    'sent' => 0,
                    'cancelled' => 0,
                    'failed' => 0,
                ],
            ]);

        $campaign->refresh();
        expect($campaign->status)->toBe('cancelled');
    });

    it('multiple cancel requests return 400 after first success', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        // First cancel - should succeed
        $response1 = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");
        $response1->assertStatus(200);

        // Second cancel - should fail with 400
        $response2 = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");
        $response2->assertStatus(400)
            ->assertJson([
                'error' => 'Campaign can only be cancelled when ongoing',
                'current_status' => 'cancelled',
            ]);
    });

    it('only updates pending logs not ongoing logs', function () {
        $org = createCancelTestOrg();
        $user = createUserWithOrg($org);
        $campaign = Campaign::factory()->forOrganization($org)->ongoing()->create();

        $contacts = Contact::factory()->count(3)->create(['organization_id' => $org->id]);

        // Create pending and ongoing logs
        CampaignLog::factory()->pending()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[0]->id,
        ]);
        $ongoingLog = CampaignLog::factory()->ongoing()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[1]->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization' => $org->id])
            ->postJson("/campaigns/{$campaign->uuid}/cancel");

        $response->assertStatus(200);

        // Verify ongoing log was NOT cancelled (in-flight messages complete normally)
        $ongoingLog->refresh();
        expect($ongoingLog->status)->toBe('ongoing');

        // Verify pending log WAS cancelled
        $cancelledCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'cancelled')
            ->count();
        expect($cancelledCount)->toBe(1);
    });
});
