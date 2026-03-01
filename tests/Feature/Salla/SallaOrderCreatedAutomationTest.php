<?php

use App\Jobs\Salla\ProcessSallaWebhookDeliveryJob;
use App\Models\Organization;
use App\Models\SallaStore;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Models\Template;
use App\Models\User;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createApprovedTemplate(int $organizationId, array $metadata): Template
{
    $user = User::factory()->create();

    return Template::create([
        'organization_id' => $organizationId,
        'meta_id' => 'meta_' . uniqid(),
        'name' => 'order_confirmation',
        'category' => 'UTILITY',
        'language' => 'en',
        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        'status' => 'APPROVED',
        'created_by' => $user->id,
    ]);
}

it('stores Salla webhook deliveries and dispatches async processing', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = SallaStore::create([
        'merchant_id' => 'm_123',
        'merchant_name' => 'Test Store',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        'scopes' => [],
        'organization_id' => $organization->id,
    ]);

    $payload = [
        'event' => 'order.created',
        'merchant' => ['id' => $store->merchant_id],
        'data' => [
            'id' => 1001,
            'customer' => [
                'name' => 'John Doe',
                'mobile' => '+15550001111',
            ],
            'total' => [
                'amount' => 120.50,
            ],
        ],
    ];

    $this->postJson('/webhook/salla', $payload)->assertOk();

    expect(SallaWebhookDelivery::count())->toBe(1);
    $delivery = SallaWebhookDelivery::first();
    expect($delivery->event)->toBe('order.created')
        ->and($delivery->merchant_id)->toBe($store->merchant_id)
        ->and($delivery->organization_id)->toBe($organization->id)
        ->and($delivery->salla_store_id)->toBe($store->id);

    Queue::assertPushed(ProcessSallaWebhookDeliveryJob::class, 1);
});

it('is idempotent for identical webhook payloads', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = SallaStore::create([
        'merchant_id' => 'm_123',
        'merchant_name' => 'Test Store',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        'scopes' => [],
        'organization_id' => $organization->id,
    ]);

    $payload = [
        'event' => 'order.created',
        'merchant' => ['id' => $store->merchant_id],
        'data' => [
            'id' => 1001,
            'customer' => [
                'name' => 'John Doe',
                'mobile' => '+15550001111',
            ],
        ],
    ];

    $this->postJson('/webhook/salla', $payload)->assertOk();
    $this->postJson('/webhook/salla', $payload)->assertOk();

    expect(SallaWebhookDelivery::count())->toBe(1);
    Queue::assertPushed(ProcessSallaWebhookDeliveryJob::class, 1);
});

it('processes order.created when enabled and mappings are configured', function () {
    $organization = Organization::factory()->create();

    $store = SallaStore::create([
        'merchant_id' => 'm_123',
        'merchant_name' => 'Test Store',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        'scopes' => [],
        'organization_id' => $organization->id,
    ]);

    $template = createApprovedTemplate($organization->id, [
        'components' => [
            ['type' => 'BODY', 'text' => 'Hi {{1}}, order #{{2}} total {{3}}'],
        ],
    ]);

    $setting = SallaWebhookEventSetting::create([
        'organization_id' => $organization->id,
        'event' => 'order.created',
        'enabled' => true,
        'template_id' => $template->id,
        'recipient_phone_payload_path' => 'data.customer.mobile',
    ]);

    $setting->mappings()->createMany([
        ['template_variable' => 'body.1', 'payload_path' => 'data.customer.name'],
        ['template_variable' => 'body.2', 'payload_path' => 'data.id'],
        ['template_variable' => 'body.3', 'payload_path' => 'data.total.amount', 'transform' => 'money'],
    ]);

    // Fake message sender so the handler doesn't call external APIs
    app()->bind(SallaWebhookMessageSenderInterface::class, function () {
        return new class implements SallaWebhookMessageSenderInterface
        {
            public function sendTemplateMessage(int $organizationId, \App\Models\Contact $contact, \App\Models\Template $template, ?array $headerMeta, array $bodyMeta, array $buttonsMeta): object
            {
                $response = new \stdClass();
                $response->success = true;
                $response->data = (object) [
                    'chat' => (object) ['id' => 999],
                ];
                return $response;
            }
        };
    });

    $payload = [
        'event' => 'order.created',
        'merchant' => ['id' => $store->merchant_id],
        'data' => [
            'id' => 1001,
            'customer' => [
                'name' => 'John Doe',
                'mobile' => '+15550001111',
            ],
            'total' => [
                'amount' => 120.50,
            ],
        ],
    ];

    $this->postJson('/webhook/salla', $payload)->assertOk();

    $delivery = SallaWebhookDelivery::firstOrFail();

    // Run the job synchronously
    (new ProcessSallaWebhookDeliveryJob($delivery->id))->handle(app(\App\Services\Salla\Webhooks\SallaWebhookEventRouter::class));

    $delivery->refresh();
    expect($delivery->status)->toBe('processed')
        ->and($delivery->sent_chat_id)->toBe(999);
});
