<?php

use AppModels\Contact;
use AppModels\Organization;
use AppModels\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatViewDetailsFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create([
        'created_by' => $user->id,
    ]);

    return [$user, $organization];
}

it('reuses an existing contact when the shared phone has a different format', function () {
    [$user, $organization] = chatViewDetailsFixture();

    $existing = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+965 9724 4282',
    ]);

    $response = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/contacts/chat-view-details', [
            'phone' => '+96597244282',
            'name' => 'Shared Contact',
        ]);

    $response->assertOk()
        ->assertJsonPath('redirect', '/contacts?id='.$existing->uuid);

    expect(Contact::withTrashed()->where('organization_id', $organization->id)->count())
        ->toBe(1)
        ->and($existing->fresh()->phone)->toBe('+96597244282');
});

it('is idempotent when View is clicked repeatedly', function () {
    [$user, $organization] = chatViewDetailsFixture();

    $payload = [
        'phone' => '+965 9724 4282',
        'name' => 'Shared Contact',
    ];

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/contacts/chat-view-details', $payload)
        ->assertOk();

    $firstContact = Contact::where('organization_id', $organization->id)->firstOrFail();

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/contacts/chat-view-details', $payload)
        ->assertOk()
        ->assertJsonPath('redirect', '/contacts?id='.$firstContact->uuid);

    expect(Contact::withTrashed()->where('organization_id', $organization->id)->count())
        ->toBe(1);
});

it('restores a soft-deleted contact instead of creating another one', function () {
    [$user, $organization] = chatViewDetailsFixture();

    $existing = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+96597244282',
    ]);
    $existing->delete();

    $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->withSession(['current_organization' => $organization->id])
        ->postJson('/contacts/chat-view-details', [
            'phone' => '+965 9724 4282',
            'name' => 'Shared Contact',
        ])
        ->assertOk()
        ->assertJsonPath('redirect', '/contacts?id='.$existing->uuid);

    expect(Contact::withTrashed()->where('organization_id', $organization->id)->count())
        ->toBe(1)
        ->and($existing->fresh()->deleted_at)->toBeNull();
});
