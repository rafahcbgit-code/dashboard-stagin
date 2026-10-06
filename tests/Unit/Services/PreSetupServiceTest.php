<?php

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\PreSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function makePreSetupOrganization(array $metadata = []): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create([
        'created_by' => $user->id,
        'metadata' => json_encode($metadata),
    ]);

    return [$user, $organization];
}

function preSetupCounts(int $contacts = 0, int $templates = 0, int $campaigns = 0, ?int $approvedTemplates = null): array
{
    return [
        'contacts' => $contacts,
        'templates' => $templates,
        'approved_templates' => $approvedTemplates ?? $templates,
        'campaigns' => $campaigns,
    ];
}

it('returns the documented ordered setup steps and defers the test message', function () {
    [, $organization] = makePreSetupOrganization();

    $result = app(PreSetupService::class)->forOrganization($organization->id, false, false, [], preSetupCounts());
    $mandatoryIds = collect($result['steps'])->where('mandatory', true)->pluck('id')->values()->all();

    expect(array_column($result['steps'], 'id'))->toBe([
        'whatsapp_connected', 'whatsapp_test_message',
        'unsubscribe_configured', 'first_template', 'contacts_imported', 'first_campaign',
        'timezone', 'working_hours', 'team_members', 'conversation_distribution',
        'ticketing', 'email_notifications', 'labels_created', 'readiness_review',
    ])->and($result['steps'][1]['mandatory'])->toBeFalse()
        ->and($result['steps'][1]['completed'])->toBeFalse()
        ->and($mandatoryIds)->toBe(['whatsapp_connected', 'unsubscribe_configured', 'first_template', 'contacts_imported', 'timezone', 'readiness_review'])
        ->and($result['mandatoryReady'])->toBeFalse()
        ->and($result['summary']['total'])->toBe(14)
        ->and($result['summary']['mandatoryPending'])->toBe(6)
        ->and($result['steps'][13]['review']['total'])->toBe(5)
        ->and($result['steps'][13]['review']['completed'])->toBe(0)
        ->and($result['steps'][13]['review']['items'][0]['state'])->toBe('needs_action')
        ->and($result['steps'][13]['review']['items'][0]['actionLabel'])->toBe(__('Set up'));
});

it('offers reconnect action when the configured WhatsApp number is disconnected', function () {
    $metadata = ['whatsapp' => [
        'phone_number_id' => 'phone-1',
        'display_phone_number' => '+966 50 000 0000',
        'number_status' => 'DISCONNECTED',
    ]];
    [, $organization] = makePreSetupOrganization($metadata);

    $result = app(PreSetupService::class)->forOrganization($organization->id, false, false, $metadata, preSetupCounts());
    $whatsappCheck = $result['steps'][13]['review']['items'][0];

    expect($whatsappCheck['state'])->toBe('blocked')
        ->and($whatsappCheck['actionLabel'])->toBe(__('Reconnect'))
        ->and($whatsappCheck['detail'])->toContain('+966 50 000 0000')
        ->and($result['steps'][13]['review']['blocked'])->toBe(1);
});

it('derives readiness from the five prerequisites and calculates visible summaries', function () {
    $metadata = [
        'whatsapp' => ['phone_number_id' => 'phone-1', 'number_status' => 'CONNECTED'],
        'timezone' => 'Asia/Damascus',
        'campaign_settings' => ['unsubscribe_trigger' => 'stop'],
    ];
    [, $organization] = makePreSetupOrganization($metadata);

    $service = app(PreSetupService::class);
    $ownerView = $service->forOrganization($organization->id, false, false, $metadata, preSetupCounts(1, 1, 0, 1));
    Cache::forget("org_{$organization->id}_pre_setup_signals_v2");
    $agentView = $service->forOrganization($organization->id, true, false, $metadata, preSetupCounts(1, 1));

    expect($ownerView['mandatoryReady'])->toBeTrue()
        ->and($ownerView['summary']['mandatoryPending'])->toBe(0)
        ->and($ownerView['steps'][13]['completed'])->toBeTrue()
        ->and($ownerView['steps'][13]['review']['completed'])->toBe(5)
        ->and($ownerView['dismissed'])->toBeFalse()
        ->and($agentView['summary']['total'])->toBe(11)
        ->and(collect($agentView['steps'])->pluck('group')->contains('team'))->toBeFalse();
});

it('requires an approved template in the readiness review', function () {
    $metadata = [
        'whatsapp' => ['phone_number_id' => 'phone-1', 'number_status' => 'CONNECTED'],
        'timezone' => 'Asia/Damascus',
        'campaign_settings' => ['unsubscribe_trigger' => 'stop'],
    ];
    [, $organization] = makePreSetupOrganization($metadata);

    $result = app(PreSetupService::class)->forOrganization(
        $organization->id,
        false,
        false,
        $metadata,
        preSetupCounts(1, 1, 0, 0)
    );
    $templateCheck = collect($result['steps'][13]['review']['items'])->firstWhere('id', 'first_template');

    expect($templateCheck['completed'])->toBeFalse()
        ->and($templateCheck['detail'])->toContain('0')
        ->and($result['mandatoryReady'])->toBeFalse();
});

it('counts active team members within the selected organization only', function () {
    [$owner, $firstOrganization] = makePreSetupOrganization();
    [$secondOwner, $secondOrganization] = makePreSetupOrganization();

    Team::create([
        'organization_id' => $firstOrganization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    Team::create([
        'organization_id' => $secondOrganization->id,
        'user_id' => $owner->id,
        'role' => 'manager',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    Team::create([
        'organization_id' => $secondOrganization->id,
        'user_id' => $secondOwner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $secondOwner->id,
    ]);

    $result = app(PreSetupService::class)->forOrganization($firstOrganization->id, false, false, [], preSetupCounts());
    $teamStep = collect($result['steps'])->firstWhere('id', 'team_members');

    expect($teamStep['completed'])->toBeFalse()
        ->and($secondOrganization->id)->not->toBe($firstOrganization->id);
});
