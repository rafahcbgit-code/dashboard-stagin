<?php

use App\Http\Middleware\CheckOrganizationId;
use App\Http\Middleware\IsTrial;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('persists checklist dismissal and reopening without losing organization metadata', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create([
        'created_by' => $user->id,
        'metadata' => json_encode([
            'timezone' => 'Asia/Damascus',
            'whatsapp' => ['phone_number_id' => 'phone-1'],
            'email_settings' => ['host' => 'smtp.example.test', 'username' => 'user@example.test'],
        ]),
    ]);

    $response = $this->withoutMiddleware([CheckOrganizationId::class, IsTrial::class])
        ->actingAs($user, 'user')
        ->withSession(['current_organization' => $organization->id])
        ->post(route('dashboard.pre-setup-state'), ['dismissed' => true]);

    $response->assertRedirect();
    $metadata = json_decode($organization->fresh()->metadata, true);
    expect($metadata['pre_setup']['dismissed'])->toBeTrue()
        ->and($metadata['timezone'])->toBe('Asia/Damascus')
        ->and($metadata['whatsapp']['phone_number_id'])->toBe('phone-1')
        ->and($metadata['email_settings']['host'])->toBe('smtp.example.test');

    $this->post(route('dashboard.pre-setup-state'), ['dismissed' => false])->assertRedirect();
    expect(json_decode($organization->fresh()->metadata, true)['pre_setup']['dismissed'])->toBeFalse();
});
