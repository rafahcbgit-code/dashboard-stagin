<?php

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function markLatestUnreadFixture(array $organizationMetadata = []): array
{
    $creator = User::factory()->create();
    $organization = Organization::factory()->create([
        'created_by' => $creator->id,
        'metadata' => json_encode($organizationMetadata),
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $creator->id,
    ]);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $creator->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $creator->id,
    ]);

    return [$creator, $organization, $contact];
}

it('marks only the latest inbound message as unread and returns the conversation count', function () {
    [$user, $organization, $contact] = markLatestUnreadFixture();

    $olderInbound = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'type' => 'inbound',
        'is_read' => 0,
        'created_at' => now()->subMinutes(3),
    ]);
    $latestInbound = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'type' => 'inbound',
        'is_read' => 1,
        'created_at' => now()->subMinutes(2),
    ]);
    $latestOutbound = Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'is_read' => 1,
        'created_at' => now()->subMinute(),
    ]);

    $response = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/chats/'.$contact->uuid.'/mark-latest-unread');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('updated_count', 1)
        ->assertJsonPath('unread_count', 2)
        ->assertJsonPath('chat_id', $latestInbound->id);

    expect($olderInbound->fresh()->is_read)->toBe(0)
        ->and($latestInbound->fresh()->is_read)->toBe(0)
        ->and($latestOutbound->fresh()->is_read)->toBe(1);
});

it('is idempotent when the latest inbound message is already unread', function () {
    [$user, $organization, $contact] = markLatestUnreadFixture();

    $latestInbound = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'type' => 'inbound',
        'is_read' => 0,
    ]);

    $response = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/chats/'.$contact->uuid.'/mark-latest-unread');

    $response->assertOk()
        ->assertJsonPath('updated_count', 0)
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('chat_id', $latestInbound->id);
});

it('rejects an agent without access to the conversation', function () {
    [$owner, $organization, $contact] = markLatestUnreadFixture([
        'tickets' => [
            'active' => true,
            'allow_agents_to_view_all_chats' => false,
        ],
    ]);
    $agent = User::factory()->create();

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $chat = Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'is_read' => 1,
    ]);

    $this
        ->withoutMiddleware()
        ->actingAs($agent)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/chats/'.$contact->uuid.'/mark-latest-unread')
        ->assertForbidden();

    expect($chat->fresh()->is_read)->toBe(1);
});

it('returns a validation error when the conversation has no inbound message', function () {
    [$user, $organization, $contact] = markLatestUnreadFixture();

    Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'is_read' => 1,
    ]);

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/chats/'.$contact->uuid.'/mark-latest-unread')
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No inbound message found');
});
