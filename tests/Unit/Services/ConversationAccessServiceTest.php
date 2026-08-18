<?php

use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\ConversationAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function createConversationAccessFixture(array $ticketSettings = []): array
{
    $creator = User::factory()->create();
    $organization = Organization::factory()->create([
        'created_by' => $creator->id,
        'metadata' => json_encode([
            'tickets' => $ticketSettings,
        ]),
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $creator->id,
    ]);

    return [$creator, $organization, $contact];
}

it('allows owners and managers to access organization conversations', function () {
    [$creator, $organization, $contact] = createConversationAccessFixture([
        'active' => true,
        'allow_agents_to_view_all_chats' => false,
    ]);

    $manager = User::factory()->create();
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $manager->id,
        'role' => 'manager',
        'status' => 'active',
        'created_by' => $creator->id,
    ]);

    expect(app(ConversationAccessService::class)->canAccess($manager, $contact))->toBeTrue();
});

it('restricts agents to assigned conversations when the organization setting requires it', function () {
    [$creator, $organization, $contact] = createConversationAccessFixture([
        'active' => true,
        'allow_agents_to_view_all_chats' => false,
    ]);

    $agent = User::factory()->create();
    $otherAgent = User::factory()->create();

    foreach ([$agent, $otherAgent] as $teamUser) {
        Team::create([
            'organization_id' => $organization->id,
            'user_id' => $teamUser->id,
            'role' => 'agent',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    ChatTicket::create([
        'contact_id' => $contact->id,
        'organization_id' => $organization->id,
        'assigned_to' => $agent->id,
        'status' => 'open',
    ]);

    $access = app(ConversationAccessService::class);

    expect($access->canAccess($agent, $contact))->toBeTrue();
    expect($access->canAccess($otherAgent, $contact))->toBeFalse();
});

it('allows agents to access all organization conversations when configured', function () {
    [$creator, $organization, $contact] = createConversationAccessFixture([
        'active' => true,
        'allow_agents_to_view_all_chats' => true,
    ]);

    $agent = User::factory()->create();
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'status' => 'active',
        'created_by' => $creator->id,
    ]);

    expect(app(ConversationAccessService::class)->canAccess($agent, $contact))->toBeTrue();
});

it('rejects users outside the conversation organization or with suspended membership', function () {
    [$creator, $organization, $contact] = createConversationAccessFixture();
    $otherOrganization = Organization::factory()->create(['created_by' => $creator->id]);

    $user = User::factory()->create();
    Team::create([
        'organization_id' => $otherOrganization->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $creator->id,
    ]);

    $suspendedUser = User::factory()->create();
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $suspendedUser->id,
        'role' => 'manager',
        'status' => 'suspended',
        'created_by' => $creator->id,
    ]);

    $access = app(ConversationAccessService::class);

    expect($access->canAccess($user, $contact))->toBeFalse();
    expect($access->canAccess($suspendedUser, $contact))->toBeFalse();
});

it('returns only internal presence identity data', function () {
    $user = User::factory()->create([
        'first_name' => 'Rafah',
        'last_name' => 'Agent',
    ]);

    expect(app(ConversationAccessService::class)->presenceData($user))
        ->toBe([
            'id' => (string) $user->id,
            'name' => 'Rafah Agent',
        ]);
});
