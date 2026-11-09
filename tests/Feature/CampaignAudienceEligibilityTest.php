<?php

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Services\CampaignAudienceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('excludes blocked and unsubscribed group members without double counting', function () {
    config(['app.faker_locale' => 'en_US']);
    $organization = Organization::factory()->create();
    $group = ContactGroup::factory()->forOrganization($organization)->create();
    $members = collect([
        ['is_subscribed' => 1, 'is_blocked' => 0],
        ['is_subscribed' => 1, 'is_blocked' => 1],
        ['is_subscribed' => 0, 'is_blocked' => 0],
        ['is_subscribed' => 0, 'is_blocked' => 1],
        ['is_subscribed' => 1, 'is_blocked' => 0, 'phone' => ''],
    ])->map(function ($attributes) use ($organization, $group) {
        $contact = Contact::factory()->create(['organization_id' => $organization->id, ...$attributes]);
        $contact->contactGroups()->attach($group->id);
        return $contact;
    });
    Contact::factory()->create(['organization_id' => $organization->id]);

    $audience = app(CampaignAudienceService::class)->preview($organization->id, $group->uuid);

    expect($audience['candidate_count'])->toBe(5)
        ->and($audience['excluded_blocked'])->toBe(2)
        ->and($audience['excluded_unsubscribed'])->toBe(1)
        ->and($audience['excluded_without_phone'])->toBe(1)
        ->and($audience['recipient_count'])->toBe(1)
        ->and(array_column($audience['preview'], 'id'))->toBe([$members->first()->id]);

    $members->first()->update(['is_subscribed' => 0]);
    expect(app(CampaignAudienceService::class)->preview($organization->id, $group->uuid)['recipient_count'])->toBe(0);
});
