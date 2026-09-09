<?php

use App\Jobs\Webhook\ProcessWebhookJob;

it('safely acknowledges account_update webhooks', function () {
    $job = new ProcessWebhookJob([
        'field' => 'account_update',
        'value' => [
            'event' => 'PARTNER_ADDED',
            'waba_info' => ['waba_id' => '123'],
        ],
    ], 1, 'account_update', ['waba_id' => '123']);

    expect(fn () => $job->handle())->not->toThrow(Throwable::class);
});
