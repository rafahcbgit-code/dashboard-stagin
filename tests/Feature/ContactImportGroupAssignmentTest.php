<?php

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImportBatch;
use App\Models\ContactImportRow;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ignores file group names when importing into selected audiences', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $selectedGroup = ContactGroup::factory()->forOrganization($organization)->create([
        'name' => 'Selected Audience',
    ]);
    $fileGroup = ContactGroup::factory()->forOrganization($organization)->create([
        'name' => 'File Audience',
    ]);

    $batch = ContactImportBatch::query()->create([
        'organization_id' => $organization->id,
        'created_by' => $user->id,
        'status' => 'group_assigned',
        'headers' => ['phone', 'group_name'],
        'mapping' => ['phone' => 'phone', 'group_name' => 'group_name'],
        'selected_group_ids' => [$selectedGroup->id],
        'group_assignment' => ['mode' => 'all_selected'],
    ]);

    ContactImportRow::query()->create([
        'contact_import_batch_id' => $batch->id,
        'row_number' => 2,
        'status' => 'valid',
        'is_removed' => false,
        'normalized_data' => [
            'first_name' => 'Imported',
            'last_name' => null,
            'phone' => '+14155552671',
            'email' => null,
            'group_names' => [$fileGroup->name],
            'address' => [],
            'custom_attributes' => [],
        ],
    ]);

    (new ContactImportService())->commit($batch, $organization->id, $user->id);

    $contact = Contact::query()
        ->where('organization_id', $organization->id)
        ->where('phone', '+14155552671')
        ->firstOrFail();

    $assignedGroupIds = $contact->contactGroups()->pluck('contact_groups.id')->all();

    expect($assignedGroupIds)->toBe([$selectedGroup->id]);
});

it('keeps existing audiences when assigning imported contacts from file group names', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $salesGroup = ContactGroup::factory()->forOrganization($organization)->create([
        'name' => 'sales',
    ]);
    $marketingGroup = ContactGroup::factory()->forOrganization($organization)->create([
        'name' => 'marketing',
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'phone' => '+14155552671',
    ]);
    $contact->contactGroups()->attach($salesGroup->id);

    $batch = ContactImportBatch::query()->create([
        'organization_id' => $organization->id,
        'created_by' => $user->id,
        'status' => 'group_assigned',
        'headers' => ['phone', 'group_name'],
        'mapping' => ['phone' => 'phone', 'group_name' => 'group_name'],
        'selected_group_ids' => [],
        'group_assignment' => ['mode' => 'file_group_name'],
    ]);

    ContactImportRow::query()->create([
        'contact_import_batch_id' => $batch->id,
        'row_number' => 2,
        'status' => 'valid',
        'is_removed' => false,
        'normalized_data' => [
            'first_name' => 'Imported',
            'last_name' => null,
            'phone' => '+14155552671',
            'email' => null,
            'group_names' => [$marketingGroup->name],
            'address' => [],
            'custom_attributes' => [],
        ],
    ]);

    (new ContactImportService())->commit($batch, $organization->id, $user->id);

    $assignedGroupIds = $contact->fresh()
        ->contactGroups()
        ->pluck('contact_groups.id')
        ->sort()
        ->values()
        ->all();

    expect($assignedGroupIds)->toBe(collect([$salesGroup->id, $marketingGroup->id])->sort()->values()->all());
});

it('does not exceed the split group size when a generated audience already has contacts', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $firstSplitGroup = ContactGroup::factory()->forOrganization($organization)->create([
        'name' => 'batch_1',
    ]);

    Contact::factory()
        ->count(80)
        ->create(['organization_id' => $organization->id])
        ->each(fn (Contact $contact) => $contact->contactGroups()->attach($firstSplitGroup->id));

    $batch = ContactImportBatch::query()->create([
        'organization_id' => $organization->id,
        'created_by' => $user->id,
        'status' => 'group_assigned',
        'headers' => ['phone'],
        'mapping' => ['phone' => 'phone'],
        'selected_group_ids' => [],
        'group_assignment' => [
            'mode' => 'split',
            'split_group_size' => 100,
            'split_group_prefix' => 'batch',
        ],
    ]);

    foreach (range(1, 25) as $index) {
        ContactImportRow::query()->create([
            'contact_import_batch_id' => $batch->id,
            'row_number' => $index + 1,
            'status' => 'valid',
            'is_removed' => false,
            'normalized_data' => [
                'first_name' => 'Imported',
                'last_name' => null,
                'phone' => sprintf('+14155553%03d', $index),
                'email' => null,
                'group_names' => [],
                'address' => [],
                'custom_attributes' => [],
            ],
        ]);
    }

    (new ContactImportService())->commit($batch, $organization->id, $user->id);

    $secondSplitGroup = ContactGroup::query()
        ->where('organization_id', $organization->id)
        ->where('name', 'batch_2')
        ->firstOrFail();

    expect($firstSplitGroup->contacts()->count())->toBe(100);
    expect($secondSplitGroup->contacts()->count())->toBe(5);
});
