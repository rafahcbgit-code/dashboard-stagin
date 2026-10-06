<?php

use App\Services\CampaignReadinessService;
use Tests\TestCase;

uses(TestCase::class);

it('blocks a campaign when the WhatsApp number is disconnected', function () {
    $result = (new CampaignReadinessService())->check(['whatsapp' => ['number_status' => 'DISCONNECTED']]);

    expect($result['ready'])->toBeFalse()
        ->and($result['blockers'])->toHaveCount(1)
        ->and($result['blockers'][0]['key'])->toBe(CampaignReadinessService::KEY_NUMBER_DISCONNECTED)
        ->and($result['blockers'][0]['action']['path'])->toBe('/settings/whatsapp')
        ->and($result['blockers'][0]['message'])->toContain("isn't connected");
});

it('fails open when organization metadata is missing or empty', function () {
    $result = (new CampaignReadinessService())->check([]);

    expect($result['ready'])->toBeTrue()
        ->and($result['blockers'])->toBeEmpty()
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['key'])->toBe(CampaignReadinessService::KEY_NUMBER_UNKNOWN);
});

it('warns about low quality without blocking the send', function () {
    $result = (new CampaignReadinessService())->check([
        'whatsapp' => ['number_status' => 'CONNECTED', 'quality_rating' => 'LOW'],
    ]);

    expect($result['ready'])->toBeTrue()
        ->and($result['blockers'])->toBeEmpty()
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['key'])->toBe(CampaignReadinessService::KEY_QUALITY_LOW);
});

it('blocks a template that is not approved yet', function () {
    $result = (new CampaignReadinessService())->check(['whatsapp' => ['number_status' => 'CONNECTED']], 'PENDING');

    expect($result['ready'])->toBeFalse()
        ->and($result['blockers'])->toHaveCount(1)
        ->and($result['blockers'][0]['key'])->toBe(CampaignReadinessService::KEY_TEMPLATE_NOT_APPROVED)
        ->and($result['blockers'][0]['action']['path'])->toBe('/templates');
});

it('blocks paused and rejected templates as well', function (string $status) {
    $result = (new CampaignReadinessService())->check(['whatsapp' => ['number_status' => 'CONNECTED']], $status);

    expect($result['ready'])->toBeFalse();
})->with(['PAUSED', 'REJECTED', 'DISABLED', 'PENDING']);

it('allows a connected number with an approved template', function (string $status) {
    $result = (new CampaignReadinessService())->check(
        ['whatsapp' => ['number_status' => 'CONNECTED', 'quality_rating' => 'GREEN']],
        $status,
    );

    expect($result['ready'])->toBeTrue()->and($result['warnings'])->toBeEmpty();
})->with(['APPROVED', 'ACTIVE', 'approved']);

it('ignores casing and surrounding whitespace in stored statuses', function () {
    $result = (new CampaignReadinessService())->check(['whatsapp' => ['number_status' => ' disconnected ']]);

    expect($result['ready'])->toBeFalse();
});
