<?php

use App\Jobs\SendChatNoteEmailNotificationJob;
use App\Models\ChatLog;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists only active notes for the selected conversation in the current organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    $contact = Contact::factory()->create(['organization_id' => $organization->id]);
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrganization->id]);

    ChatNote::create([
        'uuid' => Str::uuid()->toString(),
        'contact_id' => $contact->id,
        'content' => 'Visible note',
        'created_by' => $user->id,
    ]);
    ChatNote::create([
        'uuid' => Str::uuid()->toString(),
        'contact_id' => $contact->id,
        'content' => 'Deleted note',
        'created_by' => $user->id,
        'deleted_at' => now(),
    ]);
    ChatNote::create([
        'uuid' => Str::uuid()->toString(),
        'contact_id' => $otherContact->id,
        'content' => 'Other organization note',
        'created_by' => $user->id,
    ]);

    $response = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->getJson('/notes?contact=' . $contact->uuid);

    $response->assertOk()
        ->assertJsonPath('notes.0.content', 'Visible note')
        ->assertJsonCount(1, 'notes')
        ->assertJsonMissing(['content' => 'Deleted note'])
        ->assertJsonMissing(['content' => 'Other organization note']);
});

it('creates updates and soft deletes a note for the current organization conversation', function () {
    Queue::fake();

    $organization = Organization::factory()->create([
        'metadata' => json_encode([
            'tickets' => [
                'note_email_notifications' => [
                    'enabled' => true,
                    'recipient_scope' => 'assigned_agent',
                ],
            ],
        ]),
    ]);
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);

    $createResponse = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/notes', [
            'contact' => $contact->uuid,
            'notes' => 'Initial note',
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('note.content', 'Initial note');

    $note = ChatNote::where('contact_id', $contact->id)->firstOrFail();

    Queue::assertPushed(SendChatNoteEmailNotificationJob::class);
    expect(ChatLog::query()
        ->where('contact_id', $contact->id)
        ->where('entity_type', 'notes')
        ->where('entity_id', $note->id)
        ->whereNull('deleted_at')
        ->count())->toBe(1);

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->putJson('/notes/' . $note->uuid, [
            'content' => 'Updated note',
        ])
        ->assertOk()
        ->assertJsonPath('note.content', 'Updated note');

    expect(ChatNote::query()->where('contact_id', $contact->id)->count())->toBe(1)
        ->and(ChatLog::query()
            ->where('contact_id', $contact->id)
            ->where('entity_type', 'notes')
            ->where('entity_id', $note->id)
            ->whereNull('deleted_at')
            ->count())->toBe(1);

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->deleteJson('/notes/' . $note->uuid)
        ->assertOk();

    expect($note->fresh()->deleted_at)->not->toBeNull();
});

it('does not update notes that belong to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrganization->id]);

    $note = ChatNote::create([
        'uuid' => Str::uuid()->toString(),
        'contact_id' => $otherContact->id,
        'content' => 'Private note',
        'created_by' => $user->id,
    ]);

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->putJson('/notes/' . $note->uuid, [
            'content' => 'Should not update',
        ])
        ->assertNotFound();

    expect($note->fresh()->content)->toBe('Private note');
});
