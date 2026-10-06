<?php

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

function searchConversationFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
    ]);
    return [$owner, $organization, $contact];
}

function searchableMessage(Organization $organization, Contact $contact, string $body, ?string $wamId = null): Chat
{
    $chat = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'wam_id' => $wamId ?? 'wamid_' . uniqid(),
        'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $body]]),
    ]);
    ChatLog::create([
        'contact_id' => $contact->id,
        'entity_type' => 'chat',
        'entity_id' => $chat->id,
        'created_at' => now(),
    ]);
    return $chat;
}

it('finds Arabic text and requires every word in the same message', function () {
    [$owner, $organization, $contact] = searchConversationFixture();
    $match = searchableMessage($organization, $contact, 'هلوو يا شباب، موعد الاجتماع غداً');
    searchableMessage($organization, $contact, 'هلوو فقط');
    searchableMessage($organization, $contact, 'موعد آخر');

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->getJson('/chats/' . $contact->uuid . '/search?q=' . urlencode('موعد هلوو'))
        ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.chat_id', $match->id);
});

it('removes revoked and cleared messages from the search index', function () {
    [$owner, $organization, $contact] = searchConversationFixture();
    $chat = searchableMessage($organization, $contact, 'هلوو', 'wamid_original');
    expect(DB::table('chat_message_search')->where('chat_id', $chat->id)->exists())->toBeTrue();

    $revoke = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'metadata' => json_encode(['type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid_original']]),
    ]);
    ChatLog::create([
        'contact_id' => $contact->id, 'entity_type' => 'chat',
        'entity_id' => $revoke->id, 'created_at' => now(),
    ]);
    expect(DB::table('chat_message_search')->where('chat_id', $chat->id)->exists())->toBeFalse();

    $later = searchableMessage($organization, $contact, 'هلوو مرة أخرى', 'wamid_later');
    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->delete('/chats/' . $contact->uuid);
    expect(DB::table('chat_message_search')->where('chat_id', $later->id)->exists())->toBeFalse();
});

it('keeps search and result navigation inside the current organization', function () {
    [$owner, $organization, $contact] = searchConversationFixture();
    [, $otherOrganization, $otherContact] = searchConversationFixture();
    $foreign = searchableMessage($otherOrganization, $otherContact, 'هلوو بعيد');
    $foreignLog = ChatLog::where('entity_type', 'chat')->where('entity_id', $foreign->id)->value('id');

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->getJson('/chats/' . $contact->uuid . '/search?q=' . urlencode('هلوو'))
        ->assertOk()->assertJsonCount(0, 'results');
    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->getJson('/chats/' . $contact->uuid . '/messages/around/' . $foreignLog)
        ->assertNotFound();
});

it('keeps an archived message searchable', function () {
    [, $organization, $contact] = searchConversationFixture();
    $chat = searchableMessage($organization, $contact, 'رسالة محفوظة في الأرشيف');
    $source = (array) DB::table('chats')->where('id', $chat->id)->first();
    $columns = [
        'id', 'uuid', 'organization_id', 'wam_id', 'contact_id', 'user_id',
        'type', 'metadata', 'media_id', 'status', 'is_read',
        'deleted_by', 'deleted_at', 'created_at',
    ];
    DB::table('chats_archive')->insert(array_intersect_key($source, array_flip($columns)));
    DB::table('chats')->where('id', $chat->id)->delete();

    $results = app(\App\Services\ChatMessageSearchService::class)
        ->search($organization->id, $contact->id, 'الأرشيف');
    expect($results['results'])->toHaveCount(1)
        ->and($results['results'][0]['chat_id'])->toBe($chat->id);
});

it('does not index a message that arrives after its revoke event', function () {
    [, $organization, $contact] = searchConversationFixture();
    $revoke = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'metadata' => json_encode(['type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid_delayed']]),
    ]);
    ChatLog::create([
        'contact_id' => $contact->id, 'entity_type' => 'chat',
        'entity_id' => $revoke->id, 'created_at' => now(),
    ]);
    $original = searchableMessage($organization, $contact, 'رسالة متأخرة', 'wamid_delayed');

    expect(DB::table('chat_message_search')->where('chat_id', $original->id)->exists())->toBeFalse();
});
