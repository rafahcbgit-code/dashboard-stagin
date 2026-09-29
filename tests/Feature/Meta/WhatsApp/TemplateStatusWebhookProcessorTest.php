<?php

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Organization;
use App\Services\Meta\WhatsApp\TemplateStatusMapper;
use App\Services\Meta\WhatsApp\TemplateStatusNotificationRecorder;
use App\Services\Meta\WhatsApp\TemplateStatusWebhookProcessor;

uses(Tests\TestCase::class);

it('triggers a full template sync for a template category update', function () {
    $organization = new Organization();
    $organization->id = 42;
    $organization->metadata = json_encode(['whatsapp' => ['waba_id' => 'waba-123']]);

    $processor = Mockery::mock(TemplateStatusWebhookProcessor::class, [
        new TemplateStatusMapper(),
        app(TemplateStatusNotificationRecorder::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $processor->shouldReceive('findOrganizationByWabaId')
        ->once()
        ->with('waba-123')
        ->andReturn($organization);

    $processor->shouldReceive('syncTemplates')
        ->once()
        ->withArgs(fn (Organization $receivedOrganization, ?string $requestId) =>
            $receivedOrganization->is($organization) && $requestId === 'request-123'
        );

    $processor->process([
        'field' => 'template_category_update',
        'value' => [
            'message_template_id' => 'meta-template-123',
            'message_template_name' => 'order_update',
            'message_template_language' => 'en_US',
            'new_category' => 'UTILITY',
        ],
    ], 0, [
        'request_id' => 'request-123',
        'waba_id' => 'waba-123',
    ]);
});

it('serializes template webhook processing per organization', function () {
    $job = new ProcessWebhookJob([
        'field' => 'template_category_update',
        'value' => [],
    ], 42, 'template_category_update');

    expect($job->middleware())->toHaveCount(1);
});
