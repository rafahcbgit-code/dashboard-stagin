<?php

use App\Models\Organization;
use App\Services\CampaignRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Config::set('campaigns.throttle.default_phone_mps', 20);
    Config::set('campaigns.throttle.max_phone_mps', 80);
});

function campaignRateForOrganization(int $organizationId): int
{
    $method = new ReflectionMethod(CampaignRateLimiter::class, 'phoneRate');
    $method->setAccessible(true);

    return $method->invoke(null, $organizationId);
}

it('uses 20 messages per second when organization has no campaign speed override', function () {
    $organization = Organization::factory()->create([
        'metadata' => json_encode([
            'whatsapp' => [
                'access_token' => 'test-token',
            ],
        ]),
    ]);

    expect(campaignRateForOrganization($organization->id))->toBe(20);
});

it('uses organization campaign phone_mps override when configured', function () {
    $organization = Organization::factory()->create([
        'metadata' => json_encode([
            'campaigns' => [
                'phone_mps' => 45,
            ],
        ]),
    ]);

    expect(campaignRateForOrganization($organization->id))->toBe(45);
});

it('caps organization campaign phone_mps at configured maximum', function () {
    Config::set('campaigns.throttle.max_phone_mps', 40);

    $organization = Organization::factory()->create([
        'metadata' => json_encode([
            'campaigns' => [
                'phone_mps' => 90,
            ],
        ]),
    ]);

    expect(campaignRateForOrganization($organization->id))->toBe(40);
});
