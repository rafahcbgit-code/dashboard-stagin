<?php

use Illuminate\Support\Facades\Http;
use Modules\EmbeddedSignup\Controllers\RegisterController;

it('resolves a preferred phone only from the authorized WABA', function () {
    Http::fake([
        'graph.facebook.com/*/123/phone_numbers*' => Http::response([
            'data' => [
                ['id' => '456', 'display_phone_number' => '+15550001'],
                ['id' => '789', 'display_phone_number' => '+15550002'],
            ],
        ]),
    ]);

    $response = app(RegisterController::class)->getPhoneNumberFromWaba('token', '123', '789');

    expect($response->success)->toBeTrue()
        ->and($response->data->id)->toBe('789');
});

it('rejects a browser phone id outside the authorized WABA', function () {
    Http::fake([
        'graph.facebook.com/*/123/phone_numbers*' => Http::response([
            'data' => [['id' => '456', 'display_phone_number' => '+15550001']],
        ]),
    ]);

    $response = app(RegisterController::class)->getPhoneNumberFromWaba('token', '123', '999');

    expect($response->success)->toBeFalse()
        ->and($response->data->error->message)->toContain('does not belong');
});

it('discovers the first WABA phone when the browser event omits phone_number_id', function () {
    Http::fake([
        'graph.facebook.com/*/123/phone_numbers*' => Http::response([
            'data' => [['id' => '456', 'display_phone_number' => '+15550001']],
        ]),
    ]);

    $response = app(RegisterController::class)->getPhoneNumberFromWaba('token', '123');

    expect($response->success)->toBeTrue()
        ->and($response->data->id)->toBe('456');
});
