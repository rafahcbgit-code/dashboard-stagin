<?php

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\DashboardChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns one zero-filled aggregate series per direction for the selected period', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create(['created_by' => $user->id]);
    $contact = Contact::factory()->create(['organization_id' => $organization->id, 'created_by' => $user->id]);
    $otherOrganization = Organization::factory()->create(['created_by' => $user->id]);
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrganization->id, 'created_by' => $user->id]);

    Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'type' => 'inbound',
        'created_at' => now('UTC')->startOfDay()->addHours(2),
    ]);
    Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'created_at' => now('UTC')->startOfDay()->addHours(3),
    ]);
    Chat::factory()->create([
        'organization_id' => $otherOrganization->id,
        'contact_id' => $otherContact->id,
        'type' => 'inbound',
        'created_at' => now('UTC')->startOfDay()->addHours(1),
    ]);

    $result = app(DashboardChartService::class)->forOrganization($organization->id, 30);

    expect($result['period'])->toHaveCount(30)
        ->and($result['inbound'])->toHaveCount(30)
        ->and($result['outbound'])->toHaveCount(30)
        ->and(array_sum($result['inbound']))->toBe(1)
        ->and(array_sum($result['outbound']))->toBe(1)
        ->and(count(array_filter($result['inbound'], fn ($count) => $count === 0)))->toBe(29);
});
