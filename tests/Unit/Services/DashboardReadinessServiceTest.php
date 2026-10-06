<?php

use App\Services\DashboardReadinessService;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the setup card as the only guidance when WhatsApp configuration is missing', function () {
    expect(app(DashboardReadinessService::class)->check([]))->toBeNull()
        ->and(app(DashboardReadinessService::class)->check(['whatsapp' => null]))->toBeNull();
});

it('returns a blocking remediation when the configured WhatsApp number is disconnected', function () {
    $readiness = app(DashboardReadinessService::class)->check([
        'whatsapp' => ['phone_number_id' => 'phone-1', 'number_status' => 'DISCONNECTED'],
    ]);

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['blockers'][0]['key'])->toBe('number_disconnected')
        ->and($readiness['blockers'][0]['action']['path'])->toBe('/settings/whatsapp');
});

it('shows no warning for a connected green number and a warning for an unknown state', function () {
    $connected = app(DashboardReadinessService::class)->check([
        'whatsapp' => ['phone_number_id' => 'phone-1', 'number_status' => 'CONNECTED', 'quality_rating' => 'GREEN'],
    ]);
    $unknown = app(DashboardReadinessService::class)->check([
        'whatsapp' => ['phone_number_id' => 'phone-1', 'number_status' => 'UNKNOWN'],
    ]);

    expect($connected['blockers'])->toBeEmpty()
        ->and($connected['warnings'])->toBeEmpty()
        ->and($unknown['warnings'][0]['key'])->toBe('number_unknown')
        ->and($unknown['warnings'][0]['action']['path'])->toBe('/settings/whatsapp');
});
