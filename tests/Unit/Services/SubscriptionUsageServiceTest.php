<?php

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsageEvent;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Services\SubscriptionUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

function createUsageContext(array $limits = []): array
{
    $user = User::factory()->create();
    $organization = createTestOrganization(['created_by' => $user->id]);

    $metadata = array_merge([
        'template_messages_limit' => -1,
        'conversations_limit' => -1,
        'contacts_limit' => -1,
        'team_limit' => -1,
        'organizations_limit' => -1,
    ], $limits);

    $plan = SubscriptionPlan::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test plan',
        'price' => 10,
        'period' => 'monthly',
        'metadata' => json_encode($metadata),
        'status' => 'active',
    ]);

    $subscription = Subscription::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'start_date' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    return [$user, $organization, $plan, $subscription];
}

it('combines plan limits and active add-ons for template messages', function () {
    [, $organization] = createUsageContext([
        'template_messages_limit' => 10,
    ]);
    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
        'quantity' => 5,
        'valid_until' => now()->addDay(),
    ]);

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
        'quantity' => 99,
        'valid_until' => now()->subDay(),
    ]);

    $service = new SubscriptionUsageService();
    $service->recordUsage(
        $organization->id,
        SubscriptionUsageService::TEMPLATE_MESSAGES,
        3,
        'delivered-template-1'
    );

    $summary = $service->summary($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES);

    expect($summary['limit'])->toBe(15)
        ->and($summary['used'])->toBe(3)
        ->and($summary['remaining'])->toBe(12)
        ->and($service->canUse($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 12)['allowed'])->toBeTrue()
        ->and($service->canUse($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 13)['allowed'])->toBeFalse();
});

it('records delivered template usage idempotently', function () {
    [, $organization] = createUsageContext([
        'template_messages_limit' => 5,
    ]);

    $service = new SubscriptionUsageService();
    $service->recordUsage($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 1, 'wam-1');
    $service->recordUsage($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 1, 'wam-1');

    expect(SubscriptionUsageEvent::count())->toBe(1)
        ->and($service->summary($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES)['used'])->toBe(1);
});

it('records template usage from delivered webhooks only once', function () {
    [, $organization] = createUsageContext([
        'template_messages_limit' => 5,
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
    ]);
    $chat = Chat::factory()->outbound()->create([
        'organization_id' => $organization->id,
        'contact_id' => $contact->id,
        'wam_id' => 'wam-template-delivered-1',
        'metadata' => json_encode([
            'message_type' => 'template',
            'billing' => [
                'is_template' => true,
                'source' => 'flow',
                'source_id' => 123,
            ],
        ]),
    ]);

    $job = new ProcessWebhookJob([], $organization->id, 'messages');
    $method = new ReflectionMethod($job, 'recordDeliveredTemplateUsage');
    $method->setAccessible(true);

    $method->invoke($job, $chat, 'sent', []);
    expect(SubscriptionUsageEvent::count())->toBe(0);

    $method->invoke($job, $chat, 'delivered', ['status' => 'delivered']);
    $method->invoke($job, $chat, 'delivered', ['status' => 'delivered']);
    $method->invoke($job, $chat, 'read', ['status' => 'read']);

    $event = SubscriptionUsageEvent::first();

    expect(SubscriptionUsageEvent::count())->toBe(1)
        ->and($event->resource)->toBe(SubscriptionUsageService::TEMPLATE_MESSAGES)
        ->and($event->quantity)->toBe(1)
        ->and($event->source_type)->toBe('flow')
        ->and($event->source_id)->toBe(123);
});

it('counts 24-hour conversation windows and lets add-ons restore capacity', function () {
    [, $organization] = createUsageContext([
        'conversations_limit' => 1,
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $service = new SubscriptionUsageService();
    $service->ensureConversationWindowForInbound($organization->id, $contact->id);

    expect($service->canUse($organization->id, SubscriptionUsageService::CONVERSATIONS)['allowed'])->toBeFalse();

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::CONVERSATIONS,
        'quantity' => 1,
        'valid_until' => now()->addDay(),
    ]);

    expect($service->canUse($organization->id, SubscriptionUsageService::CONVERSATIONS)['allowed'])->toBeTrue();
});

it('shows pool totals for the resource account and local usage for shared accounts', function () {
    [$user, $resourceOrganization, $plan, $subscription] = createUsageContext([
        'template_messages_limit' => 10,
        'conversations_limit' => 10,
        'contacts_limit' => 10,
        'team_limit' => 10,
        'organizations_limit' => 10,
        'resource_sharing_enabled' => 1,
    ]);

    $sharedOrganization = createTestOrganization([
        'created_by' => $user->id,
        'billing_organization_id' => $resourceOrganization->id,
    ]);

    Subscription::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $sharedOrganization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'start_date' => $subscription->start_date,
        'valid_until' => $subscription->valid_until,
    ]);

    $resourceContact = Contact::factory()->create([
        'organization_id' => $resourceOrganization->id,
    ]);
    $sharedContact = Contact::factory()->create([
        'organization_id' => $sharedOrganization->id,
    ]);

    $service = new SubscriptionUsageService();
    $service->recordUsage($resourceOrganization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 2, 'primary-template');
    $service->recordUsage($sharedOrganization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 3, 'shared-template');
    $service->ensureConversationWindowForInbound($resourceOrganization->id, $resourceContact->id);
    $service->ensureConversationWindowForInbound($sharedOrganization->id, $sharedContact->id);

    $primaryTemplateSummary = $service->summary($resourceOrganization->id, SubscriptionUsageService::TEMPLATE_MESSAGES);
    $sharedTemplateSummary = $service->summary($sharedOrganization->id, SubscriptionUsageService::TEMPLATE_MESSAGES);
    $sharedConversationSummary = $service->summary($sharedOrganization->id, SubscriptionUsageService::CONVERSATIONS);
    $organizationSummary = $service->summary($resourceOrganization->id, SubscriptionUsageService::ORGANIZATIONS);
    $organizationRows = collect($organizationSummary['organization_breakdown'])->keyBy('organization_id');

    expect($primaryTemplateSummary['used'])->toBe(5)
        ->and($primaryTemplateSummary['is_resource_account'])->toBeTrue()
        ->and($sharedTemplateSummary['used'])->toBe(3)
        ->and($sharedTemplateSummary['pool_used'])->toBe(5)
        ->and($sharedTemplateSummary['remaining'])->toBe(5)
        ->and($sharedTemplateSummary['is_resource_account'])->toBeFalse()
        ->and($sharedConversationSummary['used'])->toBe(1)
        ->and($sharedConversationSummary['pool_used'])->toBe(2)
        ->and($organizationRows[$resourceOrganization->id]['role'])->toBe('resource_account')
        ->and($organizationRows[$resourceOrganization->id]['template_messages_used'])->toBe(2)
        ->and($organizationRows[$sharedOrganization->id]['role'])->toBe('shared_account')
        ->and($organizationRows[$sharedOrganization->id]['template_messages_used'])->toBe(3);
});

it('counts active team members and unexpired invites', function () {
    [$user, $organization] = createUsageContext([
        'team_limit' => 2,
    ]);

    Team::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    TeamInvite::create([
        'organization_id' => $organization->id,
        'email' => 'pending@example.com',
        'code' => 'pending-code',
        'invited_by' => $user->id,
        'expire_at' => now()->addDay(),
    ]);

    TeamInvite::create([
        'organization_id' => $organization->id,
        'email' => 'expired@example.com',
        'code' => 'expired-code',
        'invited_by' => $user->id,
        'expire_at' => now()->subDay(),
    ]);

    $service = new SubscriptionUsageService();
    $summary = $service->summary($organization->id, SubscriptionUsageService::TEAM_MEMBERS);

    expect($summary['used'])->toBe(2)
        ->and($service->canUse($organization->id, SubscriptionUsageService::TEAM_MEMBERS)['allowed'])->toBeFalse();
});

it('combines plan limits and active add-ons for contacts', function () {
    [, $organization] = createUsageContext([
        'contacts_limit' => 2,
    ]);

    Contact::factory()->count(3)->create([
        'organization_id' => $organization->id,
    ]);

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::CONTACTS,
        'quantity' => 2,
        'valid_until' => now()->addDay(),
    ]);

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::CONTACTS,
        'quantity' => 10,
        'valid_until' => now()->subDay(),
    ]);

    $service = new SubscriptionUsageService();
    $summary = $service->summary($organization->id, SubscriptionUsageService::CONTACTS);

    expect($summary['base_limit'])->toBe(2)
        ->and($summary['addon_quantity'])->toBe(2)
        ->and($summary['limit'])->toBe(4)
        ->and($summary['used'])->toBe(3)
        ->and($summary['remaining'])->toBe(1)
        ->and($service->canUse($organization->id, SubscriptionUsageService::CONTACTS, 1)['allowed'])->toBeTrue()
        ->and($service->canUse($organization->id, SubscriptionUsageService::CONTACTS, 2)['allowed'])->toBeFalse();
});

it('combines plan limits and active add-ons for team members', function () {
    [$user, $organization] = createUsageContext([
        'team_limit' => 1,
    ]);

    Team::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    TeamInvite::create([
        'organization_id' => $organization->id,
        'email' => 'pending-addon@example.com',
        'code' => 'pending-addon-code',
        'invited_by' => $user->id,
        'expire_at' => now()->addDay(),
    ]);

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::TEAM_MEMBERS,
        'quantity' => 2,
        'valid_until' => now()->addDay(),
    ]);

    $service = new SubscriptionUsageService();
    $summary = $service->summary($organization->id, SubscriptionUsageService::TEAM_MEMBERS);

    expect($summary['base_limit'])->toBe(1)
        ->and($summary['addon_quantity'])->toBe(2)
        ->and($summary['limit'])->toBe(3)
        ->and($summary['used'])->toBe(2)
        ->and($summary['remaining'])->toBe(1)
        ->and($service->canUse($organization->id, SubscriptionUsageService::TEAM_MEMBERS, 1)['allowed'])->toBeTrue()
        ->and($service->canUse($organization->id, SubscriptionUsageService::TEAM_MEMBERS, 2)['allowed'])->toBeFalse();
});

it('uses the primary owned organization for organization limits while creating from another organization', function () {
    [$user, $primaryOrganization] = createUsageContext([
        'organizations_limit' => 2,
    ]);

    Team::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $primaryOrganization->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $secondaryOrganization = createTestOrganization(['created_by' => $user->id]);
    $thirdOrganization = createTestOrganization(['created_by' => $user->id]);

    foreach ([$secondaryOrganization, $thirdOrganization] as $organization) {
        Team::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    $unlimitedPlan = SubscriptionPlan::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Secondary plan',
        'price' => 0,
        'period' => 'monthly',
        'metadata' => json_encode([
            'organizations_limit' => -1,
        ]),
        'status' => 'active',
    ]);

    Subscription::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $secondaryOrganization->id,
        'plan_id' => $unlimitedPlan->id,
        'status' => 'active',
        'start_date' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    $service = new SubscriptionUsageService();
    $blocked = $service->canUse($secondaryOrganization->id, SubscriptionUsageService::ORGANIZATIONS, 1, $user->id);

    expect($blocked['allowed'])->toBeFalse()
        ->and($blocked['summary']['limit'])->toBe(2)
        ->and($blocked['summary']['used'])->toBe(3);

    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $primaryOrganization->id,
        'resource' => SubscriptionUsageService::ORGANIZATIONS,
        'quantity' => 2,
        'valid_until' => now()->addDay(),
    ]);

    $allowed = $service->canUse($secondaryOrganization->id, SubscriptionUsageService::ORGANIZATIONS, 1, $user->id);

    expect($allowed['allowed'])->toBeTrue()
        ->and($allowed['summary']['limit'])->toBe(4)
        ->and($allowed['summary']['addon_quantity'])->toBe(2)
        ->and($allowed['summary']['remaining'])->toBe(1);
});

it('treats -1 as unlimited', function () {
    [, $organization] = createUsageContext([
        'contacts_limit' => -1,
    ]);

    Contact::factory()->count(3)->create([
        'organization_id' => $organization->id,
    ]);

    $result = (new SubscriptionUsageService())->canUse($organization->id, SubscriptionUsageService::CONTACTS, 1000);

    expect($result['allowed'])->toBeTrue()
        ->and($result['summary']['remaining'])->toBe(-1);
});

it('builds bulk summaries that match individual summaries', function () {
    [$user, $organization] = createUsageContext([
        'template_messages_limit' => 10,
        'conversations_limit' => 10,
        'contacts_limit' => 10,
        'team_limit' => 10,
        'organizations_limit' => 10,
    ]);
    [, $secondOrganization] = createUsageContext([
        'template_messages_limit' => 5,
        'conversations_limit' => 5,
        'contacts_limit' => 5,
        'team_limit' => 5,
        'organizations_limit' => 5,
    ]);

    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
    ]);
    Contact::factory()->count(2)->create([
        'organization_id' => $organization->id,
    ]);
    Team::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $user->id,
    ]);
    TeamInvite::create([
        'organization_id' => $organization->id,
        'email' => 'bulk-pending@example.com',
        'code' => 'bulk-pending-code',
        'invited_by' => $user->id,
        'expire_at' => now()->addDay(),
    ]);
    ResourceAddon::create([
        'uuid' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
        'quantity' => 3,
        'valid_until' => now()->addDay(),
    ]);

    $service = new SubscriptionUsageService();
    $service->recordUsage($organization->id, SubscriptionUsageService::TEMPLATE_MESSAGES, 2, 'bulk-template-1', 'campaign');
    $service->ensureConversationWindowForInbound($organization->id, $contact->id);

    $bulk = $service->bulkSummaries([$organization->id, $secondOrganization->id]);

    expect($bulk[$organization->id])->toEqual($service->summaries($organization->id))
        ->and($bulk[$secondOrganization->id])->toEqual($service->summaries($secondOrganization->id));
});
