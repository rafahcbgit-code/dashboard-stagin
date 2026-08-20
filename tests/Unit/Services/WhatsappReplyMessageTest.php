<?php

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\ChatService;
use App\Services\WhatsappService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['pusher_app_id', 'pusher_app_key', 'pusher_app_secret', 'pusher_app_cluster'] as $key) {
        Setting::create(['key' => $key, 'value' => 'test']);
    }
});

function createReplyTestOrganization(): Organization
{
    $organization = Organization::factory()->withoutAppSecret()->create();

    $plan = SubscriptionPlan::create([
        'name' => 'Reply Test Plan',
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

function fakeWhatsappClient(array &$history): void
{
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15551234567', 'wa_id' => '15551234567']],
            'messages' => [['id' => 'wamid.OUTBOUND']],
        ])),
    ]);

    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    WhatsappService::setSharedClientForTesting(new Client(['handler' => $stack]));
}

it('sends a normal text message without a WhatsApp context field', function () {
    $organization = createReplyTestOrganization();
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+15551234567',
    ]);
    $history = [];
    fakeWhatsappClient($history);

    $response = (new ChatService($organization->id))->sendMessage((object) [
        'uuid' => $contact->uuid,
        'message' => 'Hello',
        'type' => 'text',
        'user_id' => 1,
    ]);

    expect($response->success)->toBeTrue();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    expect($payload)->not->toHaveKey('context');
});

it('sends a WhatsApp reply context and stores the internal reply relationship', function () {
    $organization = createReplyTestOrganization();
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+15551234567',
    ]);
    $original = Chat::create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'wam_id' => 'wamid.ORIGINAL',
        'type' => 'inbound',
        'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'Original message']]),
        'status' => 'received',
        'is_read' => 0,
        'created_at' => now(),
    ]);
    $history = [];
    fakeWhatsappClient($history);

    $response = (new ChatService($organization->id))->sendMessage((object) [
        'uuid' => $contact->uuid,
        'message' => 'Reply message',
        'type' => 'text',
        'user_id' => 1,
        'reply_to_message_id' => $original->id,
    ]);

    expect($response->success)->toBeTrue();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    expect($payload['context'])->toBe(['message_id' => 'wamid.ORIGINAL']);

    $sent = Chat::where('wam_id', 'wamid.OUTBOUND')->first();
    expect($sent->reply_to_message_id)->toBe($original->id);
});

it('fails clearly when replying to a message from another conversation', function () {
    $organization = createReplyTestOrganization();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);
    $otherContact = Contact::factory()->create(['organization_id' => $organization->id]);
    $otherMessage = Chat::create([
        'organization_id' => $organization->id,
        'contact_id' => $otherContact->id,
        'wam_id' => 'wamid.OTHER',
        'type' => 'inbound',
        'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'Other']]),
        'status' => 'received',
        'created_at' => now(),
    ]);

    $response = (new ChatService($organization->id))->sendMessage((object) [
        'uuid' => $contact->uuid,
        'message' => 'Nope',
        'type' => 'text',
        'reply_to_message_id' => $otherMessage->id,
    ]);

    expect($response->success)->toBeFalse()
        ->and($response->data->error['code'])->toBe('REPLY_MESSAGE_NOT_FOUND');
});

it('fails clearly when the selected reply message has no WhatsApp ID', function () {
    $organization = createReplyTestOrganization();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);
    $original = Chat::create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'wam_id' => null,
        'type' => 'inbound',
        'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'Local only']]),
        'status' => 'received',
        'created_at' => now(),
    ]);

    $response = (new ChatService($organization->id))->sendMessage((object) [
        'uuid' => $contact->uuid,
        'message' => 'Nope',
        'type' => 'text',
        'reply_to_message_id' => $original->id,
    ]);

    expect($response->success)->toBeFalse()
        ->and($response->data->error['code'])->toBe('REPLY_MESSAGE_MISSING_WAM_ID');
});
