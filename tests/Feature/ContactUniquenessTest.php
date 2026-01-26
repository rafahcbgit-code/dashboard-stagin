<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Services\ContactService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ensures only one contact exists per phone number in the same organization', function () {
    // Arrange
    $organization = Organization::factory()->create();
    $service = new ContactService($organization->id);
    $phone = '+1234567890';

    $request1 = new Request();
    $request1->replace([
        'first_name' => 'First',
        'last_name' => 'User',
        'phone' => $phone,
        'email' => 'first@example.com',
        'is_subscribed' => 1,
        'metadata' => []
    ]);

    $request2 = new Request();
    $request2->replace([
        'first_name' => 'Updated',
        'last_name' => 'User',
        'phone' => $phone,
        'email' => 'updated@example.com',
        'is_subscribed' => 1,
        'metadata' => []
    ]);

    // Act
    $contact1 = $service->store($request1);
    $contact2 = $service->store($request2);

    // Assert
    expect(Contact::where('organization_id', $organization->id)->count())->toBe(1);
    expect($contact1->id)->toBe($contact2->id);
    expect($contact2->first_name)->toBe('Updated');
    expect($contact2->email)->toBe('updated@example.com');
});

it('allows the same phone number in different organizations', function () {
    // Arrange
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    $service1 = new ContactService($org1->id);
    $service2 = new ContactService($org2->id);
    
    $phone = '+1234567890';

    $request = new Request();
    $request->replace([
        'first_name' => 'Common',
        'phone' => $phone,
        'is_subscribed' => 1,
        'metadata' => []
    ]);

    // Act
    $contact1 = $service1->store($request);
    $contact2 = $service2->store($request);

    // Assert
    expect(Contact::where('phone', $phone)->count())->toBe(2);
    expect($contact1->organization_id)->not->toBe($contact2->organization_id);
});

it('restores a soft-deleted contact when the same phone number is added again', function () {
    // Arrange
    $organization = Organization::factory()->create();
    $service = new ContactService($organization->id);
    $phone = '+1234567890';

    $request = new Request();
    $request->replace([
        'first_name' => 'Deleted User',
        'phone' => $phone,
        'is_subscribed' => 1,
        'metadata' => []
    ]);

    $contact = $service->store($request);
    $contact->delete(); // Soft delete

    expect(Contact::where('organization_id', $organization->id)->count())->toBe(0);
    expect(Contact::withTrashed()->where('organization_id', $organization->id)->count())->toBe(1);

    // Act
    $restoredContact = $service->store($request);

    // Assert
    expect(Contact::where('organization_id', $organization->id)->count())->toBe(1);
    expect($restoredContact->id)->toBe($contact->id);
    expect($restoredContact->deleted_at)->toBeNull();
});

it('prevents duplicates at the database level', function () {
    // Arrange
    $organization = Organization::factory()->create();
    $phone = '+1234567890';

    Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => $phone
    ]);

    // Act & Assert
    $this->expectException(\Illuminate\Database\QueryException::class);
    
    Contact::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'organization_id' => $organization->id,
        'phone' => $phone,
        'created_by' => 0
    ]);
});
