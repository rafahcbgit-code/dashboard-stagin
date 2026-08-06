<?php

use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Label;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function awaitingReplyContacts(Contact $contact, int $organizationId, ?string $searchTerm = null)
{
    return $contact->contactsWithChats(
        $organizationId,
        $searchTerm,
        false,
        'all',
        'desc',
        'owner',
        true,
        [],
        false,
        null,
        false,
        null,
        true
    );
}

it('shows only conversations whose latest message is inbound, even when it is read', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $awaitingReply = Contact::factory()->create([
        'organization_id' => $organization->id,
        'first_name' => 'Awaiting',
    ]);
    $repliedTo = Contact::factory()->create(['organization_id' => $organization->id]);
    $outboundOnly = Contact::factory()->create(['organization_id' => $organization->id]);
    $otherOrganizationContact = Contact::factory()->create(['organization_id' => $otherOrganization->id]);

    Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $awaitingReply->id,
        'type' => 'inbound',
        'is_read' => 1,
        'created_at' => now()->subMinute(),
    ]);
    Chat::factory()->create([
        'organization_id' => $organization->id,
        'contact_id' => $repliedTo->id,
        'type' => 'inbound',
        'created_at' => now()->subMinutes(2),
    ]);
    Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $repliedTo->id,
        'created_at' => now()->subMinute(),
    ]);
    Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $outboundOnly->id,
        'created_at' => now()->subMinute(),
    ]);
    Chat::factory()->create([
        'organization_id' => $otherOrganization->id,
        'contact_id' => $otherOrganizationContact->id,
        'type' => 'inbound',
        'created_at' => now()->subMinute(),
    ]);

    $paginator = awaitingReplyContacts(new Contact, $organization->id);

    expect($paginator->total())->toBe(1)
        ->and($paginator->pluck('id')->all())->toBe([$awaitingReply->id]);
});

it('keeps the awaiting-reply filter compatible with the conversation search', function () {
    $organization = Organization::factory()->create();
    $matchingContact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'first_name' => 'Nora',
    ]);
    $otherContact = Contact::factory()->create(['organization_id' => $organization->id]);

    foreach ([$matchingContact, $otherContact] as $contact) {
        Chat::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'is_read' => 1,
            'created_at' => now()->subMinute(),
        ]);
    }

    $paginator = awaitingReplyContacts(new Contact, $organization->id, 'Nora');

    expect($paginator->total())->toBe(1)
        ->and($paginator->pluck('id')->all())->toBe([$matchingContact->id]);
});

it('combines awaiting reply with ticket, assignment, and label filters', function () {
    $organization = Organization::factory()->create();
    $assignee = User::factory()->create();
    $label = Label::create([
        'organization_id' => $organization->id,
        'title' => 'Urgent',
        'color' => '#ef4444',
    ]);
    $matchingContact = Contact::factory()->create(['organization_id' => $organization->id]);
    $closedTicketContact = Contact::factory()->create(['organization_id' => $organization->id]);

    $matchingContact->labels()->attach($label->id);
    $closedTicketContact->labels()->attach($label->id);

    ChatTicket::create([
        'contact_id' => $matchingContact->id,
        'assigned_to' => $assignee->id,
        'status' => 'open',
    ]);
    ChatTicket::create([
        'contact_id' => $closedTicketContact->id,
        'assigned_to' => $assignee->id,
        'status' => 'closed',
    ]);

    foreach ([$matchingContact, $closedTicketContact] as $contact) {
        Chat::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'is_read' => 1,
            'created_at' => now()->subMinute(),
        ]);
    }

    $paginator = (new Contact)->contactsWithChats(
        $organization->id,
        null,
        true,
        'open',
        'desc',
        'owner',
        true,
        [$label->id],
        false,
        $assignee->id,
        false,
        null,
        true
    );

    $count = (new Contact)->contactsWithChatsCount(
        $organization->id,
        null,
        true,
        'open',
        'desc',
        'owner',
        true,
        [$label->id],
        false,
        $assignee->id,
        false,
        true
    );

    expect($paginator->total())->toBe(1)
        ->and($count)->toBe($paginator->total())
        ->and($paginator->pluck('id')->all())->toBe([$matchingContact->id]);
});
