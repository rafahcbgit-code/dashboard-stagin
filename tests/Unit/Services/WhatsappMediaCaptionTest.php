<?php

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\WhatsappService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    WhatsappService::resetSharedClient();
});

function createMediaCaptionTestOrganization(): Organization
{
    $organization = Organization::factory()->withoutAppSecret()->create();
    $plan = SubscriptionPlan::create([
        'name' => 'Media Caption Test Plan',
        'price' => 100,
        'period' => 'monthly',
        'metadata' => json_encode(['message_limit' => -1, 'conversations' => -1]),
    ]);

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'valid_until' => now()->addMonth(),
        'start_date' => now(),
    ]);

    return $organization;
}

it('sends a document and its caption in one WhatsApp media payload', function () {
    foreach (['pusher_app_id', 'pusher_app_key', 'pusher_app_secret', 'pusher_app_cluster'] as $key) {
        Setting::create(['key' => $key, 'value' => 'test']);
    }

    $organization = createMediaCaptionTestOrganization();
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+15551234567',
    ]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.DOCUMENT']],
        ])),
    ]));
    $stack->push(Middleware::history($history));
    WhatsappService::setSharedClientForTesting(new Client(['handler' => $stack]));

    $response = (new WhatsappService('token', 'v20.0', 'app-id', 'phone-id', 'waba-id', 1))
        ->sendMedia($contact->uuid, 'document', 'guide.pdf', 'public/guide.pdf', 'http://localhost/media/guide.pdf', 'local', 'Please read this');

    expect($response->success)->toBeTrue();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    expect($payload['type'])->toBe('document')
        ->and($payload['document'])->toMatchArray([
            'link' => 'http://localhost/media/guide.pdf',
            'filename' => 'guide.pdf',
            'caption' => 'Please read this',
        ]);

    $chat = Chat::where('wam_id', 'wamid.DOCUMENT')->firstOrFail();
    $metadata = json_decode($chat->metadata, true);
    expect($metadata['document']['caption'])->toBe('Please read this');
});
