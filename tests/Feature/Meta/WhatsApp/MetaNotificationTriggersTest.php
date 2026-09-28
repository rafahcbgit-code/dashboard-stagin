<?php

use App\Models\NotificationEvent;
use App\Http\Controllers\WebhookController;
use App\Models\Organization;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Services\ManagedNotifications\NotificationResolver;
use App\Services\Meta\WhatsApp\AccountUpdateWebhookProcessor;
use App\Services\Meta\WhatsApp\TemplateStatusWebhookProcessor;
use App\Services\WhatsappNumberStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('managed_notifications', require base_path('config/managed_notifications.php'));
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');

    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('templates', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->string('meta_id');
        $table->string('name');
        $table->string('language');
        $table->string('status');
        $table->string('category')->nullable();
        $table->text('metadata')->nullable();
        $table->text('last_rejection_reason')->nullable();
        $table->string('quality_state')->nullable();
        $table->timestamp('last_status_change_at')->nullable();
        $table->timestamp('disabled_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    (require base_path('database/migrations/2026_09_26_000004_add_notification_transition_sequence_to_templates.php'))->up();
    foreach ([
        '2026_09_26_000001_create_notification_definitions_table.php',
        '2026_09_26_000002_create_notification_events_table.php',
        '2026_09_26_000003_create_notification_deliveries_table.php',
        '2026_09_27_000004_create_notification_email_senders_table.php',
    ] as $migration) {
        (require base_path('database/migrations/'.$migration))->up();
    }
    app(NotificationCatalog::class)->ensureDefinitions();
});

function metaTriggerOrganization(string $name = 'Tenant A', string $waba = 'waba-a', array $extra = []): int
{
    return DB::table('organizations')->insertGetId([
        'name' => $name,
        'metadata' => json_encode(['whatsapp' => array_merge([
            'waba_id' => $waba,
            'phone_number_id' => 'phone-a',
            'display_phone_number' => '+15551112222',
            'access_token' => 'token',
        ], $extra)]),
    ]);
}

function metaTriggerEvent(int $organizationId, string $type): ?NotificationEvent
{
    return NotificationEvent::where('organization_id', $organizationId)->where('type_key', $type)->first();
}

it('records each account transition once with the right tenant and payload', function ($field, $value, $type, $extra, $expected) {
    $organizationId = metaTriggerOrganization('Tenant A', 'waba-a', $extra);
    $otherId = metaTriggerOrganization('Tenant B', 'waba-b');
    $processor = app(AccountUpdateWebhookProcessor::class);
    $change = ['field' => $field, 'value' => $value];
    $processor->process($organizationId, $field, $change, ['waba_id' => 'waba-a']);
    $processor->process($organizationId, $field, $change, ['waba_id' => 'waba-a']);
    $processor->process($otherId, $field, $change, ['waba_id' => 'waba-a']);

    $event = metaTriggerEvent($organizationId, $type);
    expect($event)->not->toBeNull()
        ->and($event->variables)->toMatchArray($expected)
        ->and(NotificationEvent::where('type_key', $type)->count())->toBe(1);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
})->with([
    'META-01' => ['account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'FLAGGED']], 'meta.waba_disabled_flagged', [], ['ban_state' => 'FLAGGED', 'waba_id' => 'waba-a']],
    'META-04' => ['business_capability_update', ['max_daily_conversations_per_business' => 1000], 'meta.business_capability_changed', ['messaging_limit_tier' => 10000], ['old_limit' => '10000', 'new_limit' => '1000']],
    'META-05' => ['phone_number_name_update', ['decision' => 'REJECTED', 'display_phone_number' => '+15551112222', 'requested_verified_name' => 'Sample'], 'meta.phone_display_name_rejected', [], ['requested_name' => 'Sample']],
    'META-06' => ['account_review_update', ['decision' => 'REJECTED'], 'meta.waba_review_rejected', ['account_review_status' => 'APPROVED'], ['waba_id' => 'waba-a']],
    'META-07' => ['business_capability_update', ['max_daily_conversations_per_business' => 10000], 'meta.business_capability_changed', ['messaging_limit_tier' => 1000], ['old_limit' => '1000', 'new_limit' => '10000']],
]);

it('records reinstatement as state without sending an alert and detects a later flag', function () {
    $organizationId = metaTriggerOrganization('Tenant A', 'waba-a', ['waba_ban_state' => 'DISABLE']);
    $processor = app(AccountUpdateWebhookProcessor::class);
    $reinstated = ['value' => ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE']]];

    $processor->process($organizationId, 'account_update', $reinstated, ['waba_id' => 'waba-a']);
    $processor->process($organizationId, 'account_update', $reinstated, ['waba_id' => 'waba-a']);

    $metadata = json_decode(DB::table('organizations')->where('id', $organizationId)->value('metadata'), true);
    expect($metadata['whatsapp']['waba_ban_state'])->toBe('REINSTATE')
        ->and(NotificationEvent::where('organization_id', $organizationId)->count())->toBe(0);

    $processor->process($organizationId, 'account_update', [
        'value' => ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'FLAGGED']],
    ], ['waba_id' => 'waba-a']);

    expect(NotificationEvent::where('organization_id', $organizationId)->count())->toBe(1)
        ->and(metaTriggerEvent($organizationId, 'meta.waba_disabled_flagged')->variables['ban_state'])->toBe('FLAGGED');
});

it('keeps disabled notifications out of delivery', function () {
    $organizationId = metaTriggerOrganization();
    app(AccountUpdateWebhookProcessor::class)->process($organizationId, 'account_update', [
        'value' => ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'FLAGGED']],
    ], ['waba_id' => 'waba-a']);
    $event = metaTriggerEvent($organizationId, 'meta.waba_disabled_flagged');
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
});

it('does not notify for equivalent messaging limit formats', function () {
    $organizationId = metaTriggerOrganization('Tenant A', 'waba-a', ['messaging_limit_tier' => 'TIER_1K']);
    app(AccountUpdateWebhookProcessor::class)->process($organizationId, 'business_capability_update', [
        'value' => ['max_daily_conversations_per_business' => 1000],
    ], ['waba_id' => 'waba-a']);
    expect(NotificationEvent::count())->toBe(0);
});

it('uses one messaging limit type for both directions', function () {
    $organizationId = metaTriggerOrganization('Tenant A', 'waba-a', ['messaging_limit_tier' => 10000]);
    $processor = app(AccountUpdateWebhookProcessor::class);

    foreach ([1000, 'TIER_10K'] as $limit) {
        $processor->process($organizationId, 'business_capability_update', [
            'value' => ['max_daily_conversations_per_business' => $limit],
        ], ['waba_id' => 'waba-a']);
    }

    expect(NotificationEvent::where('organization_id', $organizationId)
        ->where('type_key', 'meta.business_capability_changed')->count())->toBe(2)
        ->and(NotificationEvent::where('organization_id', $organizationId)
            ->whereIn('type_key', ['meta.messaging_limit_increased', 'meta.messaging_limit_reduced'])->count())->toBe(0)
        ->and(array_keys(app(NotificationCatalog::class)->all()))->toContain('meta.business_capability_changed')
        ->not->toContain('meta.messaging_limit_increased', 'meta.messaging_limit_reduced');
});

it('moves pending legacy limit events to the unified type while preserving history', function () {
    $organizationId = metaTriggerOrganization();
    $definition = DB::table('notification_definitions')->where('type_key', 'meta.business_capability_changed')->first();
    foreach (['meta.messaging_limit_increased', 'meta.messaging_limit_reduced'] as $oldType) {
        $copy = (array) $definition;
        unset($copy['id']);
        $copy['type_key'] = $oldType;
        DB::table('notification_definitions')->insert($copy);
    }

    foreach ([
        ['meta.messaging_limit_increased', 'pending', 'increase'],
        ['meta.messaging_limit_reduced', 'pending', 'decrease'],
        ['meta.messaging_limit_reduced', 'resolved', 'history'],
    ] as [$type, $status, $key]) {
        DB::table('notification_events')->insert([
            'organization_id' => $organizationId,
            'type_key' => $type,
            'event_key' => hash('sha256', $key),
            'variables' => json_encode(['organization_name' => 'Tenant A', 'old_limit' => '1000', 'new_limit' => '10000']),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    (require base_path('database/migrations/2026_09_27_000001_consolidate_messaging_limit_notifications.php'))->up();

    expect(NotificationEvent::where('type_key', 'meta.business_capability_changed')->where('status', 'pending')->count())->toBe(2)
        ->and(NotificationEvent::where('type_key', 'meta.messaging_limit_reduced')->where('status', 'resolved')->count())->toBe(1)
        ->and(DB::table('notification_definitions')->whereIn('type_key', [
            'meta.messaging_limit_increased', 'meta.messaging_limit_reduced',
        ])->count())->toBe(0);
});

it('records template rejection, pause, and disable transitions only once', function ($status, $type) {
    $organizationId = metaTriggerOrganization();
    $otherId = metaTriggerOrganization('Tenant B', 'waba-b');
    $templateId = DB::table('templates')->insertGetId([
        'organization_id' => $organizationId, 'meta_id' => 'template-a',
        'name' => 'order_update', 'language' => 'en_US', 'status' => 'PENDING',
    ]);
    $processor = app(TemplateStatusWebhookProcessor::class);
    $change = ['field' => 'message_template_status_update', 'value' => [
        'event' => $status, 'message_template_id' => 'template-a',
        'message_template_name' => 'order_update', 'message_template_language' => 'en_US',
    ]];
    $processor->process($change, $organizationId, ['waba_id' => 'waba-a']);
    $processor->process($change, $organizationId, ['waba_id' => 'waba-a']);
    $processor->process($change, $otherId, ['waba_id' => 'waba-a']);
    expect(DB::table('templates')->where('id', $templateId)->value('status'))->toBe($status)
        ->and(NotificationEvent::where('type_key', $type)->count())->toBe(1)
        ->and(metaTriggerEvent($organizationId, $type)->variables['template_name'])->toBe('order_update');
    $event = metaTriggerEvent($organizationId, $type);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
})->with([
    'TMP-01' => ['REJECTED', 'meta.template_rejected'],
    'TMP-02' => ['PAUSED', 'meta.template_paused'],
    'TMP-03' => ['DISABLED', 'meta.template_disabled'],
]);

it('records a verified phone quality decrease once', function () {
    $organizationId = metaTriggerOrganization('Tenant A', 'waba-a', ['quality_rating' => 'GREEN']);
    Http::fake(['graph.facebook.com/*' => Http::response(['status' => 'CONNECTED', 'quality_rating' => 'YELLOW'])]);
    $service = app(WhatsappNumberStatusService::class);
    $service->sync($organizationId);
    $service->sync($organizationId);
    expect(NotificationEvent::where('type_key', 'meta.phone_quality_decreased')->count())->toBe(1)
        ->and(metaTriggerEvent($organizationId, 'meta.phone_quality_decreased')->variables['old_quality'])->toBe('GREEN')
        ->and(metaTriggerEvent($organizationId, 'meta.phone_quality_decreased')->variables['new_quality'])->toBe('YELLOW');
    $event = metaTriggerEvent($organizationId, 'meta.phone_quality_decreased');
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
});

it('queues quality verification only for a matching tenant phone', function () {
    Queue::fake();
    config()->set('queue.default', 'database');
    $organizationId = metaTriggerOrganization();
    $processor = app(AccountUpdateWebhookProcessor::class);
    $processor->process($organizationId, 'phone_number_quality_update', [
        'value' => ['event' => 'DOWNGRADE', 'display_phone_number' => '+15550000000'],
    ], ['waba_id' => 'waba-a']);
    Queue::assertNothingPushed();
    $processor->process($organizationId, 'phone_number_quality_update', [
        'value' => ['event' => 'DOWNGRADE', 'display_phone_number' => '+15551112222'],
    ], ['waba_id' => 'waba-a']);
    Queue::assertPushed(\App\Jobs\SyncWhatsappNumberStatusJob::class,
        fn ($job) => $job->connection === 'database' && $job->organizationId === $organizationId);
});

it('queues every Meta notification change in a batched webhook for the matching WABA', function () {
    Queue::fake();
    $organizationId = metaTriggerOrganization();
    $controller = new class extends WebhookController {
        public function __construct() {}

        public function receive(Request $request, Organization $organization)
        {
            return $this->handlePostRequest($request, $organization);
        }
    };
    $request = Request::create('/webhook', 'POST', ['entry' => [
        ['id' => 'waba-a', 'changes' => [
            ['field' => 'messages', 'value' => []],
            ['field' => 'account_review_update', 'value' => ['decision' => 'REJECTED']],
            ['field' => 'message_template_status_update', 'value' => ['event' => 'PAUSED']],
        ]],
        ['id' => 'waba-b', 'changes' => [
            ['field' => 'account_update', 'value' => ['event' => 'DISABLED_UPDATE']],
        ]],
    ]]);
    $response = $controller->receive($request, Organization::findOrFail($organizationId));
    expect($response->status())->toBe(200);
    Queue::assertPushed(ProcessWebhookJob::class, 3);
});
