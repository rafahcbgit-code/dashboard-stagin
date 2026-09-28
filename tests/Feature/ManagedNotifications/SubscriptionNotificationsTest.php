<?php

use App\Jobs\NotifySubscriptionPlanLimitIncreasesJob;
use App\Models\NotificationDefinition;
use App\Models\NotificationEvent;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Services\ManagedNotifications\NotificationEventRecorder;
use App\Services\ManagedNotifications\NotificationResolver;
use App\Services\ManagedNotifications\SubscriptionNotificationScanner;
use App\Services\SubscriptionUsageService;
use App\Services\SubscriptionPeriodService;
use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    \Carbon\Carbon::setTestNow('2026-09-28 00:00:00');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('managed_notifications.allowed_senders', ['alerts@example.com']);
    Cache::flush();

    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable();
        $table->string('name');
        $table->text('address')->nullable();
        $table->unsignedBigInteger('billing_organization_id')->nullable();
        $table->timestamps();
        $table->text('metadata')->nullable();
        $table->string('timezone')->nullable();
        $table->softDeletes();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->string('role')->default('user');
        $table->integer('status')->default(1);
        $table->softDeletes();
    });
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role');
        $table->string('status');
        $table->softDeletes();
    });
    Schema::create('notification_logs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('organization_id');
        $table->string('notification_type');
        $table->json('payload');
        $table->boolean('delivered')->default(false);
        $table->boolean('clicked')->default(false);
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('read_at')->nullable();
    });
    Schema::create('settings', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });
    Schema::create('subscription_plans', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->nullable();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('period')->default('monthly');
        $table->text('metadata');
        $table->string('status')->default('active');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->nullable();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('plan_id')->nullable();
        $table->string('status');
        $table->timestamp('start_date')->nullable();
        $table->dateTime('valid_until')->nullable();
        $table->timestamps();
    });
    Schema::create('resource_addons', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->nullable();
        $table->unsignedBigInteger('organization_id');
        $table->string('resource');
        $table->integer('quantity');
        $table->dateTime('valid_until')->nullable();
        $table->timestamps();
    });
    (require base_path('database/migrations/2026_06_24_000001_create_subscription_events_table.php'))->up();
    (require base_path('database/migrations/2026_09_27_000006_prepare_subscription_notifications.php'))->up();
    (require base_path('database/migrations/2026_09_27_000007_create_subscription_plan_notification_changes.php'))->up();
    (require base_path('database/migrations/2026_09_28_000001_create_subscription_period_archives.php'))->up();
    (require base_path('database/migrations/2026_09_28_000002_remove_subscription_renewal_mode.php'))->up();
    Schema::create('subscription_usage_events', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('subscription_id')->nullable();
        $table->string('resource');
        $table->integer('quantity');
        $table->string('source_type')->nullable();
        $table->unsignedBigInteger('source_id')->nullable();
        $table->string('idempotency_key');
        $table->text('metadata')->nullable();
        $table->dateTime('occurred_at');
        $table->timestamps();
        $table->unique(['organization_id', 'resource', 'idempotency_key']);
    });
    Schema::create('conversation_windows', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('contact_id');
        $table->unsignedBigInteger('source_chat_id')->nullable();
        $table->dateTime('opened_at');
        $table->dateTime('closes_at');
        $table->timestamps();
    });
    Schema::create('contacts', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->softDeletes();
    });
    Schema::create('team_invites', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->dateTime('expire_at');
    });
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->softDeletes();
    });
    Schema::create('role_permissions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('role_id');
        $table->string('module');
        $table->string('action');
    });

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

afterEach(function () {
    \Carbon\Carbon::setTestNow();
    DB::disableQueryLog();
    DB::disconnect('sqlite');
});

function subscriptionNotificationOrg(string $name = 'Tenant A', ?string $timezone = null): int
{
    return DB::table('organizations')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => $name, 'timezone' => $timezone]);
}

function subscriptionNotificationPlan(string $name, array $limits): SubscriptionPlan
{
    $metadata = array_merge([
        'template_messages_limit' => 100,
        'conversations_limit' => 100,
        'contacts_limit' => 100,
        'team_limit' => 10,
        'organizations_limit' => 10,
    ], $limits);

    return SubscriptionPlan::create([
        'name' => $name, 'price' => 10, 'period' => 'monthly',
        'metadata' => json_encode($metadata), 'status' => 'active',
    ]);
}

function subscriptionNotificationSubscription(int $organizationId, ?int $planId,
    string $status, string $end): Subscription
{
    return Subscription::create([
        'organization_id' => $organizationId,
        'plan_id' => $planId,
        'status' => $status,
        'start_date' => now()->subDay()->toDateTimeString(),
        'valid_until' => $end,
    ]);
}

function enableSubscriptionNotification(string $type): void
{
    NotificationDefinition::query()->where('type_key', $type)->update(['enabled' => true]);
}

it('registers all subscription types disabled with safe sample variables', function () {
    $types = array_keys(array_filter(config('managed_notifications.types'),
        fn ($type, $key) => str_starts_with($key, 'subscription.'), ARRAY_FILTER_USE_BOTH));

    expect($types)->toHaveCount(11);
    foreach ($types as $type) {
        expect(NotificationDefinition::where('type_key', $type)->value('enabled'))->toBeFalse();
        expect(array_keys(app(NotificationCatalog::class)->sampleValues($type)))
            ->toBe(config('managed_notifications.types')[$type]['variables']);
    }
});

it('projects trial start once and respects disabling', function () {
    enableSubscriptionNotification('subscription.trial_started');
    $org = subscriptionNotificationOrg();
    subscriptionNotificationSubscription($org, null, 'trial', now()->addDays(14)->toDateTimeString());
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    $scanner->projectEvents();

    expect(NotificationEvent::where('type_key', 'subscription.trial_started')->count())->toBe(1);
    NotificationDefinition::where('type_key', 'subscription.trial_started')->update(['enabled' => false]);
    subscriptionNotificationSubscription(subscriptionNotificationOrg('Tenant B'), null,
        'trial', now()->addDays(14)->toDateTimeString());
    $scanner->projectEvents();
    expect(NotificationEvent::where('type_key', 'subscription.trial_started')->count())->toBe(1);
});

it('detects a trial activation on a status transition', function () {
    enableSubscriptionNotification('subscription.trial_started');
    $org = subscriptionNotificationOrg();
    $subscription = subscriptionNotificationSubscription($org, null, 'suspended',
        now()->addDays(14)->toDateTimeString());
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    $subscription->update(['status' => 'trial']);
    $scanner->projectEvents();

    expect(NotificationEvent::where('type_key', 'subscription.trial_started')->count())->toBe(1);
});

it('classifies paid activation, upgrade, downgrade, and renewal from actual transitions', function () {
    foreach (['activated', 'upgraded', 'downgraded', 'renewed'] as $type) {
        enableSubscriptionNotification('subscription.'.$type);
    }
    $org = subscriptionNotificationOrg();
    $small = subscriptionNotificationPlan('Small', ['contacts_limit' => 100]);
    $large = subscriptionNotificationPlan('Large', ['contacts_limit' => 200]);
    $subscription = subscriptionNotificationSubscription($org, $small->id, 'active', now()->addDays(20)->toDateTimeString());
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    $subscription->update(['plan_id' => $large->id]);
    $scanner->projectEvents();
    $subscription->update(['plan_id' => $small->id]);
    $scanner->projectEvents();
    $subscription->update(['valid_until' => now()->addDays(40)->toDateTimeString()]);
    $scanner->projectEvents();
    $scanner->projectEvents();

    foreach (['activated', 'upgraded', 'downgraded', 'renewed'] as $type) {
        expect(NotificationEvent::where('type_key', 'subscription.'.$type)->count())->toBe(1);
    }
    expect(NotificationEvent::where('organization_id', $org)->count())->toBe(4);
});

it('sends local trial reminders and expiry once without treating a trial plan as paid', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 09:30:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    foreach (['trial_expiring', 'trial_expired', 'expiring', 'expired'] as $type) {
        enableSubscriptionNotification('subscription.'.$type);
    }
    $org = subscriptionNotificationOrg('Tenant A', 'UTC');
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'trial', '2026-10-04 08:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.trial_expiring')->count())->toBe(1);
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(0);

    \Carbon\Carbon::setTestNow('2026-10-04 09:00:00');
    $scanner->scanDates();
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.trial_expired')->count())->toBe(1);
    expect(NotificationEvent::where('type_key', 'subscription.expired')->count())->toBe(0);
    expect($subscription->fresh()->expiry_notification_processed_at)->not->toBeNull();
});

it('uses each organization timezone for reminder delivery', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 08:30:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.trial_expiring');
    $local = subscriptionNotificationOrg('Damascus', 'Asia/Damascus');
    $utc = subscriptionNotificationOrg('UTC', 'UTC');
    subscriptionNotificationSubscription($local, null, 'trial', '2026-10-04 20:00:00');
    subscriptionNotificationSubscription($utc, null, 'trial', '2026-10-04 20:00:00');
    app(SubscriptionNotificationScanner::class)->scanDates();

    expect(NotificationEvent::where('type_key', 'subscription.trial_expiring')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.trial_expiring')->first()->organization_id)
        ->toBe($local);
});

it('reminds every active paid subscription once at seven days and one day and ignores superseded records', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expiring');
    $plan = subscriptionNotificationPlan('Plan', []);
    $firstOrg = subscriptionNotificationOrg('First tenant');
    subscriptionNotificationSubscription($firstOrg, $plan->id, 'active', '2026-10-08 20:00:00');
    $secondOrg = subscriptionNotificationOrg('Second tenant');
    subscriptionNotificationSubscription($secondOrg, $plan->id, 'active', '2026-10-08 20:00:00');
    $current = subscriptionNotificationSubscription($secondOrg, $plan->id, 'active', '2026-11-08 20:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();
    $scanner->scanDates();

    $first = NotificationEvent::where('type_key', 'subscription.expiring')->sole();
    expect($first->organization_id)->toBe($firstOrg)
        ->and($first->variables)->toEqual(['organization_name' => 'First tenant',
            'subscription_end_date' => '2026-10-08', 'days_remaining' => '7', 'plan_name' => 'Plan']);
    $current->update(['valid_until' => '2026-10-08 20:00:00']);
    $scanner->scanDates();
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(2)
        ->and(NotificationEvent::where('type_key', 'subscription.expiring')->where('organization_id', $secondOrg)->count())->toBe(1);

    \Carbon\Carbon::setTestNow('2026-10-07 10:00:00');
    $scanner->scanDates();
    $scanner->scanDates();
    $reminders = NotificationEvent::where('type_key', 'subscription.expiring')->get();
    expect($reminders)->toHaveCount(4);
    foreach ([$firstOrg, $secondOrg] as $organizationId) {
        expect($reminders->where('organization_id', $organizationId)->pluck('variables.days_remaining')->all())
            ->toBe(['7', '1']);
    }
});

it('excludes trials, suspended, expired and unpaid subscriptions from paid reminders', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expiring');
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription(subscriptionNotificationOrg('Trial'), $plan->id, 'trial', '2026-10-08 20:00:00');
    subscriptionNotificationSubscription(subscriptionNotificationOrg('Suspended'), $plan->id, 'suspended', '2026-10-08 20:00:00');
    subscriptionNotificationSubscription(subscriptionNotificationOrg('Unpaid'), null, 'active', '2026-10-08 20:00:00');
    subscriptionNotificationSubscription(subscriptionNotificationOrg('Expired'), $plan->id, 'active', '2026-10-01 09:00:00');
    $deletedOrg = subscriptionNotificationOrg('Deleted');
    subscriptionNotificationSubscription($deletedOrg, $plan->id, 'active', '2026-10-08 20:00:00');
    DB::table('organizations')->where('id', $deletedOrg)->update(['deleted_at' => now()]);
    app(SubscriptionNotificationScanner::class)->scanDates();
    expect(NotificationEvent::count())->toBe(0);
});

it('respects the paid reminder enable switch', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-08 20:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();
    expect(NotificationEvent::count())->toBe(0);
    enableSubscriptionNotification('subscription.expiring');
    $scanner->scanDates();
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(1);
    NotificationDefinition::where('type_key', 'subscription.expiring')->update(['enabled' => false]);
    \Carbon\Carbon::setTestNow('2026-10-07 10:00:00');
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(1);
});

it('never selects an older active subscription after the latest subscription is suspended', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification('subscription.expiring');
    $plan = subscriptionNotificationPlan('Plan', []);
    $suspendedOrg = subscriptionNotificationOrg('Suspended');
    subscriptionNotificationSubscription($suspendedOrg, $plan->id, 'active', '2026-10-08 20:00:00');
    subscriptionNotificationSubscription($suspendedOrg, $plan->id, 'suspended', '2026-10-08 20:00:00');
    $activeOrg = subscriptionNotificationOrg('Active');
    subscriptionNotificationSubscription($activeOrg, $plan->id, 'active', '2026-10-08 20:00:00');
    app(SubscriptionNotificationScanner::class)->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->sole()->organization_id)->toBe($activeOrg);
});

it('uses the organization local expiration date for paid reminders', function () {
    \Carbon\Carbon::setTestNow('2026-10-02 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expiring');
    $plan = subscriptionNotificationPlan('Plan', []);
    $local = subscriptionNotificationOrg('Damascus', 'Asia/Damascus');
    $utc = subscriptionNotificationOrg('UTC', 'UTC');
    subscriptionNotificationSubscription($local, $plan->id, 'active', '2026-10-08 22:00:00');
    subscriptionNotificationSubscription($utc, $plan->id, 'active', '2026-10-08 22:00:00');
    app(SubscriptionNotificationScanner::class)->scanDates();
    $event = NotificationEvent::where('type_key', 'subscription.expiring')->sole();
    expect($event->organization_id)->toBe($local)
        ->and($event->variables['subscription_end_date'])->toBe('2026-10-09')
        ->and($event->variables['days_remaining'])->toBe('7');
});

it('uses the extended expiration for paid reminders and does not remind the old period end', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expiring');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-08 20:00:00');
    manageTestSubscriptionPeriod($org, 'extend', subscriptionPeriodInput($subscription, '2026-10-31 20:00:00'));
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();
    expect(NotificationEvent::count())->toBe(0);
    \Carbon\Carbon::setTestNow('2026-10-24 10:00:00');
    $scanner->scanDates();
    $scanner->scanDates();
    \Carbon\Carbon::setTestNow('2026-10-30 10:00:00');
    $scanner->scanDates();
    $scanner->scanDates();
    $reminders = NotificationEvent::where('type_key', 'subscription.expiring')->get();
    expect($reminders)->toHaveCount(2)
        ->and($reminders->pluck('variables.subscription_end_date')->unique()->all())->toBe(['2026-10-31'])
        ->and($reminders->pluck('variables.days_remaining')->all())->toBe(['7', '1']);
});

it('removes the previous renewal classification without changing subscription dates or reminder eligibility', function (string $previousMode) {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expiring');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-08 20:00:00');
    $migration = require base_path('database/migrations/2026_09_28_000002_remove_subscription_renewal_mode.php');
    expect(Schema::hasColumn('subscriptions', 'renewal_mode'))->toBeFalse();
    $migration->down();
    DB::table('subscriptions')->where('id', $subscription->id)->update(['renewal_mode' => $previousMode]);
    $migration->up();
    expect(Schema::hasColumn('subscriptions', 'renewal_mode'))->toBeFalse()
        ->and($subscription->fresh()->getRawOriginal('valid_until'))->toBe('2026-10-08 20:00:00')
        ->and($subscription->fresh()->status)->toBe('active');
    app(SubscriptionNotificationScanner::class)->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(1);
})->with(['manual', 'automatic', 'unknown']);

it('detects paid expiry from time even while status remains active', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->where('key', 'subscription_notifications_started_at')
        ->update(['value' => '2026-09-30 00:00:00']);
    enableSubscriptionNotification('subscription.expired');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-01 09:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();
    $scanner->scanDates();

    expect(NotificationEvent::where('type_key', 'subscription.expired')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.expired')->first()->organization_id)->toBe($org);
});

it('skips processed expiries without locking and still detects a changed expiration', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification('subscription.expired');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-01 09:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanDates();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $scanner->scanDates();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn ($sql) => str_contains($sql, 'organizations'))->all())->toBe([])
        ->and($queries->filter(fn ($sql) => preg_match('/^(insert|update)/i', $sql))->all())->toBe([]);

    // Also handle an expiry changed outside Eloquent without clearing the old marker.
    DB::table('subscriptions')->where('id', $subscription->id)->update(['valid_until' => '2026-10-02 09:00:00']);
    \Carbon\Carbon::setTestNow('2026-10-02 10:00:00');
    $scanner->scanDates();
    expect(NotificationEvent::where('type_key', 'subscription.expired')->count())->toBe(2);
});

it('checks recorded reminders in bounded batches without rewriting or locking each subscription', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification('subscription.expiring');
    $plan = subscriptionNotificationPlan('Plan', []);
    for ($i = 0; $i < 210; $i++) {
        $org = subscriptionNotificationOrg('Tenant '.$i);
        $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-08 20:00:00');
        app(NotificationEventRecorder::class)->record($org, 'subscription.expiring',
            'subscription-reminder:'.$subscription->id.':2026-10-08 20:00:00:7', [
                'organization_name' => 'Tenant '.$i, 'plan_name' => 'Plan',
                'subscription_end_date' => '2026-10-08', 'days_remaining' => '7',
            ]);
    }
    DB::table('organizations')->update(['name' => 'Renamed after the reminder']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(SubscriptionNotificationScanner::class)->scanDates();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->count())->toBeLessThanOrEqual(12)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from "notification_events"'))->count())->toBe(2)
        ->and($queries->filter(fn ($sql) => preg_match('/^(insert|update)/i', $sql))->all())->toBe([])
        ->and(NotificationEvent::where('type_key', 'subscription.expiring')->count())->toBe(210);
});

it('does not rewrite resource alert states when usage and limits are unchanged', function () {
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturn([$org => array_fill_keys([
        'template_messages', 'conversations', 'contacts', 'team_members', 'organizations',
    ], ['billing_organization_id' => $org, 'limit' => 100, 'used' => 10])]);
    app()->instance(SubscriptionUsageService::class, $usage);
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanUsage();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $scanner->scanUsage();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn ($sql) => preg_match('/^(insert|update|savepoint)/i', $sql))->all())->toBe([])
        ->and(DB::table('subscription_usage_alert_states')->count())->toBe(5);
});

it('rolls back all resource states and events if one resource cannot be recorded', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturn([$org => array_fill_keys(['contacts', 'team_members'],
        ['billing_organization_id' => $org, 'limit' => 100, 'used' => 80])]);
    $realRecorder = app(NotificationEventRecorder::class);
    $recorder = Mockery::mock(NotificationEventRecorder::class)->makePartial();
    $recorder->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($realRecorder) {
        if ($args[3]['resource_name'] === 'Team members') {
            throw new RuntimeException('Simulated recording failure.');
        }
        return $realRecorder->record(...$args);
    });
    app()->instance(SubscriptionUsageService::class, $usage);
    app()->instance(NotificationEventRecorder::class, $recorder);
    expect(fn () => app(SubscriptionNotificationScanner::class)->scanUsage())->toThrow(RuntimeException::class)
        ->and(NotificationEvent::count())->toBe(0)
        ->and(DB::table('subscription_usage_alert_states')->count())->toBe(0);
});

it('keeps resource alerts on the resource owner and chooses the highest crossed threshold', function () {
    enableSubscriptionNotification('subscription.resource_warning');
    $owner = subscriptionNotificationOrg('Resource owner');
    $member = subscriptionNotificationOrg('Shared member');
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription($owner, $plan->id, 'active', now()->addDays(20)->toDateTimeString());
    subscriptionNotificationSubscription($member, $plan->id, 'active', now()->addDays(20)->toDateTimeString());

    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $used = 95;
    $usage->shouldReceive('bulkSummaries')->andReturnUsing(function () use ($owner, $member, &$used) {
        $summary = ['billing_organization_id' => $owner, 'limit' => 100, 'used' => $used];
        return [$owner => ['contacts' => $summary], $member => ['contacts' => $summary]];
    });
    app()->instance(SubscriptionUsageService::class, $usage);
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanUsage();
    $scanner->scanUsage();
    expect(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(0);

    $used = 100;
    $scanner->scanUsage();
    $scanner->scanUsage();
    $event = NotificationEvent::where('type_key', 'subscription.resource_warning')->first();
    expect($event->organization_id)->toBe($owner)
        ->and($event->variables['threshold'])->toBe('100')
        ->and(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(1);
});

it('emits each resource threshold once during a new period', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $used = 71;
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturnUsing(function () use ($org, &$used) {
        return [$org => ['contacts' => [
            'billing_organization_id' => $org, 'limit' => 100, 'used' => $used,
        ]]];
    });
    app()->instance(SubscriptionUsageService::class, $usage);
    $scanner = app(SubscriptionNotificationScanner::class);
    foreach ([71, 85, 95, 105, 60, 75] as $amount) {
        $used = $amount;
        $scanner->scanUsage();
    }

    expect(NotificationEvent::where('type_key', 'subscription.resource_warning')
        ->orderBy('id')->get()->pluck('variables')->map(fn ($values) => $values['threshold'])->all())
        ->toBe(['70', '80', '90', '100']);
});

it('records a paid plan limit increase once per resource owner', function () {
    enableSubscriptionNotification('subscription.limit_increased');
    $owner = subscriptionNotificationOrg('Resource owner');
    $member = subscriptionNotificationOrg('Shared member');
    $plan = subscriptionNotificationPlan('Plan', ['contacts_limit' => 200]);
    $new = json_decode($plan->metadata, true);
    $old = array_replace($new, ['contacts_limit' => 100]);
    subscriptionNotificationSubscription($owner, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    subscriptionNotificationSubscription($member, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturnUsing(function () use ($owner, $member) {
        return [
            $owner => ['contacts' => ['billing_organization_id' => $owner, 'addon_quantity' => 0]],
            $member => ['contacts' => ['billing_organization_id' => $owner, 'addon_quantity' => 0]],
        ];
    });
    $job = new NotifySubscriptionPlanLimitIncreasesJob($plan->id, $old, $new, 'change-1');
    $job->handle(app(NotificationEventRecorder::class), $usage);
    $job->handle(app(NotificationEventRecorder::class), $usage);

    expect(NotificationEvent::where('type_key', 'subscription.limit_increased')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.limit_increased')->first()->organization_id)->toBe($owner);
});

it('keeps plan changes durable and queues each pending change once', function () {
    $plan = subscriptionNotificationPlan('Plan', ['contacts_limit' => 200]);
    $new = json_decode($plan->metadata, true);
    $old = array_replace($new, ['contacts_limit' => 100]);
    $changeId = DB::table('subscription_plan_notification_changes')->insertGetId([
        'plan_id' => $plan->id,
        'change_id' => (string) \Illuminate\Support\Str::uuid(),
        'old_metadata' => json_encode($old),
        'new_metadata' => json_encode($new),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Queue::fake();
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->queuePlanChanges();
    $scanner->queuePlanChanges();
    Queue::assertPushed(NotifySubscriptionPlanLimitIncreasesJob::class, 1);
    expect(DB::table('subscription_plan_notification_changes')->where('id', $changeId)
        ->value('queued_at'))->not->toBeNull();
});

it('treats a new paid subscription period as renewal and ignores mixed plan changes', function () {
    foreach (['activated', 'renewed', 'upgraded', 'downgraded'] as $type) {
        enableSubscriptionNotification('subscription.'.$type);
    }
    $org = subscriptionNotificationOrg();
    $firstPlan = subscriptionNotificationPlan('First', []);
    $mixedPlan = subscriptionNotificationPlan('Mixed', [
        'contacts_limit' => 200, 'team_limit' => 5,
    ]);
    subscriptionNotificationSubscription($org, $firstPlan->id, 'active', now()->addDays(20)->toDateTimeString());
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    subscriptionNotificationSubscription($org, $firstPlan->id, 'active', now()->addDays(50)->toDateTimeString());
    $scanner->projectEvents();
    $current = Subscription::currentForOrganization($org);
    $current->update(['plan_id' => $mixedPlan->id]);
    $scanner->projectEvents();

    expect(NotificationEvent::where('type_key', 'subscription.activated')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.renewed')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.upgraded')->count())->toBe(0)
        ->and(NotificationEvent::where('type_key', 'subscription.downgraded')->count())->toBe(0);
});

it('uses stored add-on transition limits and sends to the resource account', function () {
    enableSubscriptionNotification('subscription.limit_increased');
    $account = subscriptionNotificationOrg('Resource owner');
    subscriptionNotificationOrg('Shared member');
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($account, $plan->id, 'active', now()->addDays(20)->toDateTimeString());
    $addonId = DB::table('resource_addons')->insertGetId([
        'organization_id' => $account, 'resource' => 'contacts',
        'quantity' => 50, 'created_at' => now(), 'updated_at' => now(),
    ]);
    SubscriptionEvent::create([
        'organization_id' => $account,
        'resource_addon_id' => $addonId,
        'event_type' => 'add_on_purchased',
        'new_values' => ['resource' => 'contacts', 'quantity' => 50,
            'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'old_limit' => 100, 'new_limit' => 150],
        'occurred_at' => now(),
    ]);
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('resourceAccountId')->andReturn($account);
    app()->instance(SubscriptionUsageService::class, $usage);
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    $scanner->projectEvents();

    $event = NotificationEvent::where('type_key', 'subscription.limit_increased')->first();
    expect($event->organization_id)->toBe($account)
        ->and($event->variables['old_limit'])->toBe('100')
        ->and($event->variables['new_limit'])->toBe('150')
        ->and(NotificationEvent::where('type_key', 'subscription.limit_increased')->count())->toBe(1);
});

it('fixes resource warning delivery modes by threshold', function () {
    enableSubscriptionNotification('subscription.resource_warning');
    NotificationDefinition::where('type_key', 'subscription.resource_warning')->update(['delivery_mode' => 'email']);
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $period = $subscription->usagePeriodKey();
    DB::table('subscription_usage_alert_states')->insert([
        'organization_id' => $org, 'resource' => 'contacts', 'period_key' => $period,
        'last_limit' => 100, 'highest_threshold' => 90, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = DB::table('users')->insertGetId(['email' => 'owner@example.com', 'status' => 1]);
    DB::table('teams')->insert(['organization_id' => $org, 'user_id' => $user,
        'role' => 'owner', 'status' => 'active']);
    $recorder = app(NotificationEventRecorder::class);
    $resolver = app(NotificationResolver::class);
    $base = ['organization_name' => 'Tenant A', 'resource_name' => 'Contacts',
        'usage' => '70', 'limit' => '100', 'threshold' => '70'];
    $lower = $recorder->record($org, 'subscription.resource_warning', 'subscription-usage:'.$org.':contacts:'.$period.':100:70', $base);
    $resolver->resolve($lower->id);
    expect(DB::table('notification_deliveries')->where('event_id', $lower->id)->pluck('channel')->all())
        ->toBe(['dashboard']);

    $higher = $recorder->record($org, 'subscription.resource_warning', 'subscription-usage:'.$org.':contacts:'.$period.':100:90',
        array_replace($base, ['usage' => '90', 'threshold' => '90']));
    $resolver->resolve($higher->id);
    expect(DB::table('notification_deliveries')->where('event_id', $higher->id)
        ->orderBy('channel')->pluck('channel')->all())->toBe(['dashboard', 'email']);
});

it('does not backfill subscription events that predate launch', function () {
    enableSubscriptionNotification('subscription.trial_started');
    $org = subscriptionNotificationOrg();
    subscriptionNotificationSubscription($org, null, 'trial', now()->addDays(10)->toDateTimeString());
    $lastSourceId = SubscriptionEvent::max('id');
    DB::table('settings')->where('key', 'subscription_notification_source_start_id')
        ->update(['value' => (string) $lastSourceId]);
    app(SubscriptionNotificationScanner::class)->projectEvents();

    expect(NotificationEvent::count())->toBe(0);
});

function subscriptionPeriodInput(Subscription $subscription, ?string $end = null): array
{
    $subscription->refresh();
    return ['subscription_id' => $subscription->id, 'version' => $subscription->period_version,
        'valid_until' => $end ?? now()->addDays(30)->toDateTimeString(), 'operation_key' => (string) Str::uuid()];
}

function manageTestSubscriptionPeriod(int $org, string $action, array $input): void
{
    app(SubscriptionPeriodService::class)->manage(DB::table('organizations')->where('id', $org)->value('uuid'), $action, $input, 1);
}

it('extends without resetting usage or emitting a renewal notification', function () {
    enableSubscriptionNotification('subscription.renewed');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    $start = $subscription->getRawOriginal('start_date');
    (new SubscriptionUsageService())->recordUsage($org, 'template_messages', 12, 'before-extension');
    manageTestSubscriptionPeriod($org, 'extend', subscriptionPeriodInput($subscription));

    app(SubscriptionNotificationScanner::class)->projectEvents();
    expect($subscription->fresh()->getRawOriginal('start_date'))->toBe($start)
        ->and((new SubscriptionUsageService())->summary($org, 'template_messages')['used'])->toBe(12)
        ->and(DB::table('subscription_period_archives')->count())->toBe(0)
        ->and(SubscriptionEvent::where('source', 'admin_extend')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.renewed')->count())->toBe(0);
});

it('archives renewal usage and excludes old events even in the same second', function () {
    Carbon\Carbon::setTestNow('2026-09-28 12:00:00');
    enableSubscriptionNotification('subscription.renewed');
    $org = subscriptionNotificationOrg();
    $other = subscriptionNotificationOrg('Other tenant');
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(30)->toDateTimeString());
    $otherSubscription = subscriptionNotificationSubscription($other, $plan->id, 'active', now()->addDays(30)->toDateTimeString());
    DB::table('contacts')->insert(['organization_id' => $org]);
    $owner = DB::table('users')->insertGetId(['email' => 'owner@example.com']);
    DB::table('teams')->insert(['organization_id' => $org, 'user_id' => $owner, 'role' => 'owner', 'status' => 'active']);
    $usage = new SubscriptionUsageService();
    $usage->recordUsage($org, 'template_messages', 25, 'old-messages');
    $usage->recordUsage($other, 'template_messages', 8, 'other-messages');
    $usage->ensureConversationWindowForInbound($org, 1);
    // Renewal can begin a new period without changing its end date.
    $input = subscriptionPeriodInput($subscription);
    manageTestSubscriptionPeriod($org, 'renew', $input);
    $archive = DB::table('subscription_period_archives')->sole();
    $snapshot = json_decode($archive->usage_snapshot, true);
    $current = (new SubscriptionUsageService())->summaries($org);
    expect($snapshot['template_messages']['used'])->toBe(25)
        ->and($snapshot['conversations']['used'])->toBe(1)
        ->and($current['template_messages']['used'])->toBe(0)
        ->and($current['conversations']['used'])->toBe(0)
        ->and($current['contacts']['used'])->toBe(1)
        ->and($current['team_members']['used'])->toBe(1)
        ->and($current['organizations']['used'])->toBe(1)
        ->and($otherSubscription->fresh()->usage_event_start_id)->toBe(0)
        ->and(DB::table('subscription_usage_events')->count())->toBe(2);
    $newUsage = new SubscriptionUsageService();
    $newUsage->recordUsage($org, 'template_messages', 3, 'new-messages');
    $newUsage->ensureConversationWindowForInbound($org, 2);
    // An exact retry must preserve new usage and create no second archive.
    manageTestSubscriptionPeriod($org, 'renew', $input);
    $bulk = (new SubscriptionUsageService())->bulkSummaries([$org, $other]);
    expect($bulk[$org]['template_messages']['used'])->toBe(3)
        ->and($bulk[$org]['conversations']['used'])->toBe(1)
        ->and($bulk[$other]['template_messages']['used'])->toBe(8)
        ->and(DB::table('subscription_period_archives')->count())->toBe(1);
    app(SubscriptionNotificationScanner::class)->projectEvents();
    app(SubscriptionNotificationScanner::class)->projectEvents();
    expect(NotificationEvent::where('type_key', 'subscription.renewed')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.renewed')->value('organization_id'))->toBe($org);
});

it('resets the shared resource period only through its resource account', function () {
    $account = subscriptionNotificationOrg('Resource account');
    $child = subscriptionNotificationOrg('Shared organization');
    DB::table('organizations')->where('id', $child)->update(['billing_organization_id' => $account]);
    $plan = subscriptionNotificationPlan('Shared', ['resource_sharing_enabled' => 1]);
    $subscription = subscriptionNotificationSubscription($account, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    $childSubscription = subscriptionNotificationSubscription($child, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    (new SubscriptionUsageService())->recordUsage($child, 'template_messages', 20, 'shared-messages');
    expect(fn () => manageTestSubscriptionPeriod($child, 'renew', subscriptionPeriodInput($childSubscription)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    manageTestSubscriptionPeriod($account, 'renew', subscriptionPeriodInput($subscription));
    $snapshot = json_decode(DB::table('subscription_period_archives')->sole()->usage_snapshot, true);
    $summaries = (new SubscriptionUsageService())->bulkSummaries([$account, $child]);
    expect($snapshot['template_messages']['used'])->toBe(20)
        ->and($summaries[$child]['template_messages']['used'])->toBe(0)
        ->and($summaries[$account]['organizations']['used'])->toBe(2)
        ->and(DB::table('subscription_period_archives')->value('organization_id'))->toBe($account);
});

it('rejects shortening, past dates, stale versions and a replacement subscription', function () {
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    foreach ([now()->subDay()->toDateTimeString(), now()->addDays(5)->toDateTimeString()] as $end) {
        expect(fn () => manageTestSubscriptionPeriod($org, 'extend', subscriptionPeriodInput($subscription, $end)))
            ->toThrow(Illuminate\Validation\ValidationException::class);
    }
    $stale = subscriptionPeriodInput($subscription);
    $subscription->update(['status' => 'suspended']);
    expect(fn () => manageTestSubscriptionPeriod($org, 'renew', $stale))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    $input = subscriptionPeriodInput($subscription);
    subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    expect(fn () => manageTestSubscriptionPeriod($org, 'renew', $input))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(DB::table('subscription_period_archives')->count())->toBe(0);
});

it('uses organization timezone and renews expired paid subscriptions', function () {
    Carbon\Carbon::setTestNow('2026-09-28 12:00:00');
    enableSubscriptionNotification('subscription.renewed');
    enableSubscriptionNotification('subscription.activated');
    $org = subscriptionNotificationOrg('Tenant', 'Asia/Damascus');
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'suspended', now()->subHour()->toDateTimeString());
    manageTestSubscriptionPeriod($org, 'renew', subscriptionPeriodInput($subscription, '2026-10-28T15:00'));
    app(SubscriptionNotificationScanner::class)->projectEvents();
    expect($subscription->fresh()->getRawOriginal('valid_until'))->toBe('2026-10-28 12:00:00')
        ->and($subscription->fresh()->status)->toBe('active')
        ->and(NotificationEvent::where('type_key', 'subscription.renewed')->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', 'subscription.activated')->count())->toBe(0);
});

it('rolls back the archive and usage boundary if the renewal transaction fails', function () {
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    (new SubscriptionUsageService())->recordUsage($org, 'template_messages', 9, 'before-rollback');
    $input = subscriptionPeriodInput($subscription);
    try {
        DB::transaction(function () use ($org, $input) {
            manageTestSubscriptionPeriod($org, 'renew', $input);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Rollback');
    }
    expect(DB::table('subscription_period_archives')->count())->toBe(0)
        ->and(SubscriptionEvent::where('source', 'admin_renew')->count())->toBe(0)
        ->and((new SubscriptionUsageService())->summary($org, 'template_messages')['used'])->toBe(9);
});

it('authorizes and validates the admin period actions', function (string $action) {
    config()->set('session.driver', 'array');
    config()->set('admin_permissions', require base_path('config/admin_permissions.php'));
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ]);
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    $url = '/admin/organizations/'.DB::table('organizations')->where('id', $org)->value('uuid').'/subscription/'.$action;
    $adminId = DB::table('users')->insertGetId(['email' => 'admin@example.com', 'role' => 'admin']);
    $viewerId = DB::table('users')->insertGetId(['email' => 'viewer@example.com', 'role' => 'viewer']);
    $this->actingAs(App\Models\User::find($viewerId), 'admin')->post($url, subscriptionPeriodInput($subscription))->assertForbidden();
    $this->actingAs(App\Models\User::find($adminId), 'admin')->post($url, [])->assertSessionHasErrors(['valid_until', 'subscription_id', 'version', 'operation_key']);
    $this->post($url, subscriptionPeriodInput($subscription))->assertRedirect()->assertSessionHasNoErrors();
    expect(SubscriptionEvent::where('source', 'admin_'.$action)->count())->toBe(1);
})->with(['extend', 'renew']);

it('preserves the period on ordinary admin edits and rolls back a forged expiration change', function () {
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    $start = $subscription->getRawOriginal('start_date');
    $end = $subscription->getRawOriginal('valid_until');
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    $service = app(App\Services\OrganizationService::class);
    $input = ['name' => 'Edited tenant', 'plan' => $plan->uuid, 'subscription_status' => 'active'];
    $service->update(Illuminate\Http\Request::create('/', 'PUT', $input), $uuid);
    expect($subscription->fresh()->getRawOriginal('start_date'))->toBe($start)
        ->and($subscription->fresh()->getRawOriginal('valid_until'))->toBe($end);
    $input['name'] = 'Must roll back';
    $input['valid_until'] = now()->addDays(50)->toDateTimeString();
    expect(fn () => $service->update(Illuminate\Http\Request::create('/', 'PUT', $input), $uuid))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(DB::table('organizations')->where('id', $org)->value('name'))->toBe('Edited tenant');
});

it('saves a chosen expiration when assigning or changing a plan without resetting usage', function (bool $hasPaidPlan) {
    enableSubscriptionNotification('subscription.activated');
    enableSubscriptionNotification('subscription.upgraded');
    enableSubscriptionNotification('subscription.renewed');
    $org = subscriptionNotificationOrg('Tenant', 'Asia/Damascus');
    $otherOrg = subscriptionNotificationOrg('Other tenant');
    $oldPlan = subscriptionNotificationPlan('Basic', []);
    $newPlan = subscriptionNotificationPlan('Higher', ['template_messages_limit' => 200]);
    $subscription = subscriptionNotificationSubscription($org, $hasPaidPlan ? $oldPlan->id : null,
        $hasPaidPlan ? 'active' : 'trial', '2026-10-08 00:00:00');
    $otherSubscription = subscriptionNotificationSubscription($otherOrg, $oldPlan->id, 'active', '2026-10-08 00:00:00');
    $usage = new SubscriptionUsageService();
    $usage->recordUsage($org, 'template_messages', 12, 'before-plan-change');
    $period = $subscription->usagePeriodKey();
    $start = $subscription->getRawOriginal('start_date');
    $version = $subscription->fresh()->period_version;
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    $input = ['name' => 'Tenant', 'plan' => $newPlan->uuid, 'subscription_status' => 'active',
        'valid_until' => '2026-11-01T15:30:00', 'subscription_id' => $subscription->id,
        'subscription_version' => $version];
    $service = app(App\Services\OrganizationService::class);
    $service->update(Illuminate\Http\Request::create('/', 'PUT', $input), $uuid);
    $subscription->refresh();
    expect($subscription->plan_id)->toBe($newPlan->id)
        ->and($subscription->getRawOriginal('valid_until'))->toBe('2026-11-01 12:30:00')
        ->and($subscription->getRawOriginal('start_date'))->toBe($start)
        ->and($subscription->usagePeriodKey())->toBe($period)
        ->and($subscription->period_version)->toBe($version + 1)
        ->and((new SubscriptionUsageService())->summary($org, 'template_messages')['used'])->toBe(12)
        ->and(DB::table('subscription_period_archives')->count())->toBe(0)
        ->and($otherSubscription->fresh()->getRawOriginal('valid_until'))->toBe('2026-10-08 00:00:00');
    app(SubscriptionNotificationScanner::class)->projectEvents();
    app(SubscriptionNotificationScanner::class)->projectEvents();
    $type = $hasPaidPlan ? 'subscription.upgraded' : 'subscription.activated';
    expect(NotificationEvent::where('type_key', $type)->where('organization_id', $org)->count())->toBe(1)
        ->and(NotificationEvent::where('type_key', $type)->where('organization_id', $org)->value('organization_id'))->toBe($org)
        ->and(NotificationEvent::where('type_key', 'subscription.renewed')->count())->toBe(0);
})->with([false, true]);

it('uses the chosen local expiration when adding the first subscription to an existing organization', function (bool $future) {
    $org = subscriptionNotificationOrg('Tenant', 'Asia/Damascus');
    $plan = subscriptionNotificationPlan('Paid', []);
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    $input = ['name' => 'Assigned tenant', 'plan' => $plan->uuid, 'subscription_status' => 'active',
        'valid_until' => $future ? '2026-11-01T15:30:00' : '2026-09-28T02:30:00'];
    $save = fn () => app(App\Services\OrganizationService::class)->update(
        Illuminate\Http\Request::create('/', 'PUT', $input), $uuid);
    if (! $future) {
        expect($save)->toThrow(Illuminate\Validation\ValidationException::class);
        expect(Subscription::where('organization_id', $org)->count())->toBe(0)
            ->and(DB::table('organizations')->where('id', $org)->value('name'))->toBe('Tenant');
        return;
    }
    $save();
    $subscription = Subscription::currentForOrganization($org);
    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->getRawOriginal('valid_until'))->toBe('2026-11-01 12:30:00')
        ->and(Subscription::where('organization_id', $org)->count())->toBe(1);
})->with([false, true]);

it('preserves an expired subscription when its local expiration is submitted unchanged', function () {
    $org = subscriptionNotificationOrg('Tenant', 'Asia/Damascus');
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-09-27 20:00:00');
    $version = $subscription->refresh()->period_version;
    $events = SubscriptionEvent::count();
    $input = ['name' => 'Edited tenant', 'plan' => $plan->uuid, 'subscription_status' => 'active',
        'valid_until' => '2026-09-27T23:00:00', 'subscription_id' => $subscription->id,
        'subscription_version' => $version];
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    app(App\Services\OrganizationService::class)->update(Illuminate\Http\Request::create('/', 'PUT', $input), $uuid);
    expect($subscription->fresh()->getRawOriginal('valid_until'))->toBe('2026-09-27 20:00:00')
        ->and($subscription->fresh()->period_version)->toBe($version)
        ->and(SubscriptionEvent::count())->toBe($events);
});

it('rejects a plan assignment with an expiration that has passed in the organization timezone', function () {
    $org = subscriptionNotificationOrg('Tenant', 'Asia/Damascus');
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, null, 'trial', '2026-10-08 00:00:00');
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    $input = ['name' => 'Must roll back', 'plan' => $plan->uuid, 'subscription_status' => 'active',
        'valid_until' => '2026-09-28T02:30:00'];
    expect(fn () => app(App\Services\OrganizationService::class)->update(
        Illuminate\Http\Request::create('/', 'PUT', $input), $uuid))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect($subscription->fresh()->plan_id)->toBeNull()
        ->and($subscription->fresh()->getRawOriginal('valid_until'))->toBe('2026-10-08 00:00:00')
        ->and(DB::table('organizations')->where('id', $org)->value('name'))->toBe('Tenant');
});

it('rejects a stale or another tenant subscription when changing a plan and expiration', function (string $conflict) {
    $org = subscriptionNotificationOrg('Tenant');
    $other = subscriptionNotificationOrg('Other tenant');
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, null, 'trial', '2026-10-08 00:00:00');
    $otherSubscription = subscriptionNotificationSubscription($other, null, 'trial', '2026-10-08 00:00:00');
    $input = ['name' => 'Must roll back', 'plan' => $plan->uuid, 'subscription_status' => 'active',
        'valid_until' => '2026-11-01T12:00:00', 'subscription_id' => $subscription->id,
        'subscription_version' => $subscription->period_version];
    if ($conflict === 'version') {
        $subscription->update(['valid_until' => '2026-10-10 00:00:00']);
    } elseif ($conflict === 'other tenant') {
        $input['subscription_id'] = $otherSubscription->id;
    } else {
        subscriptionNotificationSubscription($org, null, 'trial', '2026-10-10 00:00:00');
    }
    $uuid = DB::table('organizations')->where('id', $org)->value('uuid');
    expect(fn () => app(App\Services\OrganizationService::class)->update(
        Illuminate\Http\Request::create('/', 'PUT', $input), $uuid))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(Subscription::currentForOrganization($org)->plan_id)->toBeNull()
        ->and($otherSubscription->fresh()->plan_id)->toBeNull()
        ->and(DB::table('organizations')->where('id', $org)->value('name'))->toBe('Tenant');
})->with(['version', 'other tenant', 'replacement']);

it('keeps threshold deduplication on extension and opens a new usage period on renewal', function () {
    Carbon\Carbon::setTestNow('2026-09-28 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    (new SubscriptionUsageService())->recordUsage($org, 'template_messages', 80, 'first-80');
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanUsage();
    manageTestSubscriptionPeriod($org, 'extend', subscriptionPeriodInput($subscription));
    $scanner->scanUsage();
    expect(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(1);
    manageTestSubscriptionPeriod($org, 'renew', subscriptionPeriodInput($subscription));
    (new SubscriptionUsageService())->recordUsage($org, 'template_messages', 80, 'second-80');
    $scanner->scanUsage();
    $scanner->scanUsage();
    expect(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(2);
});

it('discards usage sampled before a concurrent renewal', function () {
    Carbon\Carbon::setTestNow('2026-09-28 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addDays(10)->toDateTimeString());
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    (new SubscriptionUsageService())->recordUsage($org, 'template_messages', 90, 'old-90');
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->once()->andReturnUsing(function ($ids) use ($org, $subscription) {
        $summaries = (new SubscriptionUsageService())->bulkSummaries($ids);
        manageTestSubscriptionPeriod($org, 'renew', subscriptionPeriodInput($subscription));
        return $summaries;
    });
    app()->instance(SubscriptionUsageService::class, $usage);
    app(SubscriptionNotificationScanner::class)->scanUsage();
    expect(DB::table('subscription_usage_alert_states')->count())->toBe(0)
        ->and(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(0);
});

it('aggregates a shared resource pool once across several scanner batches', function () {
    enableSubscriptionNotification('subscription.resource_warning');
    $owner = subscriptionNotificationOrg('Resource owner');
    $plan = subscriptionNotificationPlan('Shared plan', [
        'resource_sharing_enabled' => 1, 'organizations_limit' => 10000,
    ]);
    subscriptionNotificationSubscription($owner, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    for ($i = 0; $i < 210; $i++) {
        $child = subscriptionNotificationOrg('Child '.$i);
        DB::table('organizations')->where('id', $child)->update(['billing_organization_id' => $owner]);
        subscriptionNotificationSubscription($child, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    }
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->once()->andReturnUsing(fn ($ids) =>
        (new SubscriptionUsageService())->bulkSummaries($ids));
    app()->instance(SubscriptionUsageService::class, $usage);
    app(SubscriptionNotificationScanner::class)->scanUsage();
    expect(DB::table('subscription_usage_alert_states')->count())->toBe(5)
        ->and(DB::table('subscription_usage_alert_states')->distinct()->pluck('organization_id')->all())->toBe([$owner]);
});

function subscriptionDateReviewEvent(string $type): NotificationEvent
{
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification($type);
    $org = subscriptionNotificationOrg('Date review', 'UTC');
    $plan = subscriptionNotificationPlan('Paid', []);
    $trial = str_contains($type, '.trial_');
    $end = str_ends_with($type, 'expired') ? '2026-10-01 09:00:00'
        : ($trial ? '2026-10-04 20:00:00' : '2026-10-08 20:00:00');
    subscriptionNotificationSubscription($org, $trial ? null : $plan->id, $trial ? 'trial' : 'active', $end);
    $user = DB::table('users')->insertGetId(['email' => 'owner@example.com', 'status' => 1]);
    DB::table('teams')->insert(['organization_id' => $org, 'user_id' => $user, 'role' => 'owner', 'status' => 'active']);
    app(SubscriptionNotificationScanner::class)->scanDates();
    return NotificationEvent::where('type_key', $type)->sole();
}

it('cancels a queued date alert when its subscription has already been extended', function (string $type) {
    $event = subscriptionDateReviewEvent($type);
    Subscription::currentForOrganization($event->organization_id)->update(['valid_until' => '2026-11-01 20:00:00']);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled')
        ->and(DB::table('notification_deliveries')->count())->toBe(0)
        ->and(DB::table('notification_logs')->count())->toBe(0);
})->with(['subscription.trial_expiring', 'subscription.expiring', 'subscription.trial_expired', 'subscription.expired']);

it('does not email an already resolved expiry alert after renewal', function () {
    $event = subscriptionDateReviewEvent('subscription.expired');
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = App\Models\NotificationDelivery::where('event_id', $event->id)->where('channel', 'email')->sole();
    $subscription = Subscription::currentForOrganization($event->organization_id);
    manageTestSubscriptionPeriod($event->organization_id, 'renew', subscriptionPeriodInput($subscription));
    Illuminate\Support\Facades\Mail::shouldReceive('send')->never();
    app(App\Services\ManagedNotifications\NotificationDispatcher::class)->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('canceled');
});

it('does not resolve a late reminder saying seven days when only six remain', function () {
    $event = subscriptionDateReviewEvent('subscription.expiring');
    \Carbon\Carbon::setTestNow('2026-10-02 10:00:00');
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
});

it('allows a current date alert to deliver to its owner', function (string $type) {
    $event = subscriptionDateReviewEvent($type);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('resolved')
        ->and(DB::table('notification_logs')->where('organization_id', $event->organization_id)->count())->toBe(1)
        ->and(DB::table('notification_deliveries')->where('channel', 'email')->count())->toBe(1);
})->with(['subscription.trial_expiring', 'subscription.expiring', 'subscription.trial_expired', 'subscription.expired']);

it('renders activation and trial end dates using the organization timezone', function (string $status, string $type, string $variable) {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification($type);
    $org = subscriptionNotificationOrg('Damascus', 'Asia/Damascus');
    $plan = subscriptionNotificationPlan('Paid', []);
    subscriptionNotificationSubscription($org, $status === 'trial' ? null : $plan->id, $status, '2026-10-08 22:00:00');
    app(SubscriptionNotificationScanner::class)->projectEvents();
    expect(NotificationEvent::where('type_key', $type)->sole()->variables[$variable])->toBe('2026-10-09');
})->with([
    ['trial', 'subscription.trial_started', 'trial_end_date'],
    ['active', 'subscription.activated', 'subscription_end_date'],
]);

it('does not repeat a resource threshold or throw when its previous limit returns', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    $limit = 100;
    $used = 90;
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturnUsing(function () use ($org, &$limit, &$used) {
        return [$org => ['contacts' => ['billing_organization_id' => $org, 'limit' => $limit, 'used' => $used]]];
    });
    app()->instance(SubscriptionUsageService::class, $usage);
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->scanUsage();
    $limit = 200;
    $scanner->scanUsage();
    $limit = 100;
    $used = 95;
    $scanner->scanUsage();
    expect(NotificationEvent::where('type_key', 'subscription.resource_warning')->count())->toBe(1)
        ->and(DB::table('subscription_usage_alert_states')->where('resource', 'contacts')->value('highest_threshold'))->toBe(90);
});

it('rolls back notification projection together with its source marker', function () {
    enableSubscriptionNotification('subscription.activated');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $failOnce = true;
    DB::listen(function ($query) use (&$failOnce) {
        if ($failOnce && str_starts_with($query->sql, 'update "subscription_events"')) {
            $failOnce = false;
            throw new RuntimeException('Simulated source-marker failure.');
        }
    });
    $scanner = app(SubscriptionNotificationScanner::class);
    expect(fn () => $scanner->projectEvents())->toThrow(RuntimeException::class)
        ->and(NotificationEvent::count())->toBe(0)
        ->and(SubscriptionEvent::sole()->notification_projected_at)->toBeNull();
    $scanner->projectEvents();
    expect(NotificationEvent::where('type_key', 'subscription.activated')->count())->toBe(1);
});

it('keeps a plan limit alert valid after an unrelated metadata edit and skips it on retry', function () {
    enableSubscriptionNotification('subscription.limit_increased');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', ['contacts_limit' => 200]);
    subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $new = json_decode($plan->metadata, true);
    $old = array_replace($new, ['contacts_limit' => 100]);
    DB::table('subscription_plans')->where('id', $plan->id)->update(['metadata' => json_encode($new + ['description' => 'Updated'])]);
    $job = new NotifySubscriptionPlanLimitIncreasesJob($plan->id, $old, $new, 'metadata-review');
    $job->handle(app(NotificationEventRecorder::class), new SubscriptionUsageService());
    expect(NotificationEvent::where('type_key', 'subscription.limit_increased')->count())->toBe(1);
    DB::table('organizations')->where('id', $org)->update(['name' => 'Renamed']);
    $job->handle(app(NotificationEventRecorder::class), new SubscriptionUsageService());
    expect(NotificationEvent::where('type_key', 'subscription.limit_increased')->count())->toBe(1);
});

it('does not send a plan allowance alert after a concurrent suspension or plan replacement', function (string $change) {
    enableSubscriptionNotification('subscription.limit_increased');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Paid', ['contacts_limit' => 200]);
    $replacement = subscriptionNotificationPlan('Replacement', ['contacts_limit' => 1000]);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $new = json_decode($plan->metadata, true);
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->once()->andReturnUsing(function ($ids) use ($subscription, $change, $replacement) {
        $summaries = (new SubscriptionUsageService())->bulkSummaries($ids);
        $subscription->update($change === 'suspended' ? ['status' => 'suspended'] : ['plan_id' => $replacement->id]);
        return $summaries;
    });
    $job = new NotifySubscriptionPlanLimitIncreasesJob($plan->id, array_replace($new, ['contacts_limit' => 100]), $new, 'concurrent-review');
    $job->handle(app(NotificationEventRecorder::class), $usage);
    expect(NotificationEvent::where('type_key', 'subscription.limit_increased')->count())->toBe(0);
})->with(['suspended', 'replacement']);

it('cancels a date alert from a superseded subscription even when its replacement has the same expiration', function () {
    $event = subscriptionDateReviewEvent('subscription.expiring');
    $subscription = Subscription::currentForOrganization($event->organization_id);
    subscriptionNotificationSubscription($event->organization_id, $subscription->plan_id, 'active', $subscription->getRawOriginal('valid_until'));
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
});

it('cancels a trial reminder after paid activation with the same expiration', function () {
    $event = subscriptionDateReviewEvent('subscription.trial_expiring');
    $plan = subscriptionNotificationPlan('Paid', []);
    Subscription::currentForOrganization($event->organization_id)->update(['status' => 'active', 'plan_id' => $plan->id]);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled');
});

it('classifies resource increases regardless of snapshot key order', function () {
    enableSubscriptionNotification('subscription.upgraded');
    $org = subscriptionNotificationOrg();
    $small = subscriptionNotificationPlan('Small', []);
    $large = subscriptionNotificationPlan('Large', ['contacts_limit' => 200]);
    $subscription = subscriptionNotificationSubscription($org, $small->id, 'active', now()->addMonth()->toDateTimeString());
    $scanner = app(SubscriptionNotificationScanner::class);
    $scanner->projectEvents();
    $subscription->update(['plan_id' => $large->id]);
    $source = SubscriptionEvent::whereNull('notification_projected_at')->orderByDesc('id')->firstOrFail();
    $new = $source->new_values;
    $new['resource_limits'] = array_reverse($new['resource_limits'], true);
    $source->update(['new_values' => $new]);
    $scanner->projectEvents();
    expect(NotificationEvent::where('type_key', 'subscription.upgraded')->count())->toBe(1);
});

it('rejects an add-on transition pointing to another organization add-on', function () {
    enableSubscriptionNotification('subscription.limit_increased');
    $org = subscriptionNotificationOrg();
    $other = subscriptionNotificationOrg('Other');
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    $addon = DB::table('resource_addons')->insertGetId(['organization_id' => $other, 'resource' => 'contacts', 'quantity' => 50]);
    SubscriptionEvent::create([
        'organization_id' => $org, 'resource_addon_id' => $addon, 'event_type' => 'add_on_purchased', 'occurred_at' => now(),
        'new_values' => ['resource' => 'contacts', 'quantity' => 50, 'subscription_id' => $subscription->id,
            'plan_id' => $plan->id, 'subscription_status' => 'active', 'old_limit' => 100, 'new_limit' => 150],
    ]);
    app(SubscriptionNotificationScanner::class)->projectEvents();
    expect(NotificationEvent::count())->toBe(0);
});

it('retains threshold identities until a long subscription period ends or is renewed', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Annual', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', '2027-10-01 12:00:00');
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturn([$org => ['contacts' => [
        'billing_organization_id' => $org, 'limit' => 100, 'used' => 90,
    ]]]);
    app()->instance(SubscriptionUsageService::class, $usage);
    app(SubscriptionNotificationScanner::class)->scanUsage();
    $event = NotificationEvent::sole();
    app(NotificationResolver::class)->resolve($event->id);
    \Carbon\Carbon::setTestNow('2027-05-01 12:00:00');
    $this->artisan('notifications:prune')->assertSuccessful();
    expect(NotificationEvent::sole()->id)->toBe($event->id);
    manageTestSubscriptionPeriod($org, 'renew', subscriptionPeriodInput($subscription));
    $this->artisan('notifications:prune')->assertSuccessful();
    expect(NotificationEvent::whereKey($event->id)->exists())->toBeFalse();
});

function subscriptionResourceReviewEvent(): NotificationEvent
{
    \Carbon\Carbon::setTestNow('2026-10-01 12:00:00');
    enableSubscriptionNotification('subscription.resource_warning');
    $org = subscriptionNotificationOrg();
    $plan = subscriptionNotificationPlan('Plan', []);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'active', now()->addMonth()->toDateTimeString());
    Subscription::withoutEvents(fn () => $subscription->update(['start_date' => now()]));
    $owner = DB::table('users')->insertGetId(['email' => 'owner@example.com', 'status' => 1]);
    DB::table('teams')->insert(['organization_id' => $org, 'user_id' => $owner, 'role' => 'owner', 'status' => 'active']);
    $usage = Mockery::mock(SubscriptionUsageService::class)->makePartial();
    $usage->shouldReceive('bulkSummaries')->andReturn([$org => ['contacts' => [
        'billing_organization_id' => $org, 'limit' => 100, 'used' => 90,
    ]]]);
    app()->instance(SubscriptionUsageService::class, $usage);
    app(SubscriptionNotificationScanner::class)->scanUsage();
    return NotificationEvent::sole();
}

it('cancels a queued resource warning after renewal, expiry, suspension, or allowance increase', function (string $change) {
    $event = subscriptionResourceReviewEvent();
    $subscription = Subscription::currentForOrganization($event->organization_id);
    if ($change === 'renew') {
        \Carbon\Carbon::setTestNow(now()->addSecond());
        manageTestSubscriptionPeriod($event->organization_id, 'renew', subscriptionPeriodInput($subscription));
    } elseif ($change === 'expired') {
        \Carbon\Carbon::setTestNow('2026-11-02 12:00:00');
    } elseif ($change === 'suspended') {
        $subscription->update(['status' => 'suspended']);
    } elseif ($change === 'plan') {
        $plan = subscriptionNotificationPlan('Larger', ['contacts_limit' => 200]);
        $subscription->update(['plan_id' => $plan->id]);
    } else {
        DB::table('resource_addons')->insert(['organization_id' => $event->organization_id, 'resource' => 'contacts', 'quantity' => 100]);
    }
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('canceled')->and(DB::table('notification_deliveries')->count())->toBe(0);
})->with(['renew', 'expired', 'suspended', 'plan', 'add-on']);

it('cancels a resolved resource email after its usage period is renewed', function () {
    $event = subscriptionResourceReviewEvent();
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = App\Models\NotificationDelivery::where('channel', 'email')->sole();
    \Carbon\Carbon::setTestNow(now()->addSecond());
    $subscription = Subscription::currentForOrganization($event->organization_id);
    manageTestSubscriptionPeriod($event->organization_id, 'renew', subscriptionPeriodInput($subscription));
    Illuminate\Support\Facades\Mail::shouldReceive('send')->never();
    app(App\Services\ManagedNotifications\NotificationDispatcher::class)->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('canceled');
});

it('preserves a current resource warning after an extension without recounting usage', function () {
    $event = subscriptionResourceReviewEvent();
    Subscription::currentForOrganization($event->organization_id)->update(['valid_until' => '2026-12-01 12:00:00']);
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(NotificationResolver::class)->resolve($event->id);
    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($event->fresh()->status)->toBe('resolved')
        ->and($queries->filter(fn ($sql) => str_contains($sql, '"contacts"') || str_contains($sql, '"subscription_usage_events"'))->count())->toBe(0)
        ->and(DB::table('notification_logs')->count())->toBe(1);
});

it('uses the actual trial expiry for allowance checks regardless of the selected organization timezone', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    DB::table('settings')->insert(['key' => 'trial_limits', 'value' => json_encode(['contacts' => 100])]);
    Cache::forget('settings:trial_limits');
    $org = subscriptionNotificationOrg('Trial');
    $other = subscriptionNotificationOrg('Other');
    DB::table('organizations')->where('id', $other)->update(['metadata' => json_encode(['timezone' => 'Pacific/Honolulu'])]);
    $plan = subscriptionNotificationPlan('Paid', ['contacts_limit' => 200]);
    $subscription = subscriptionNotificationSubscription($org, $plan->id, 'trial', '2026-10-01 10:30:00');
    session()->put('current_organization', $other);
    try {
        expect((new SubscriptionUsageService())->effectiveLimit($subscription, 'contacts'))->toBe(100);
    } finally {
        session()->forget('current_organization');
    }
});

it('keeps a reminder immutable when overlapping scanners observe an organization rename', function () {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    enableSubscriptionNotification('subscription.expiring');
    $org = subscriptionNotificationOrg('Original');
    $plan = subscriptionNotificationPlan('Paid', []);
    subscriptionNotificationSubscription($org, $plan->id, 'active', '2026-10-08 20:00:00');
    $scanner = app(SubscriptionNotificationScanner::class);
    $overlapped = false;
    DB::listen(function ($query) use ($org, $scanner, &$overlapped) {
        if (! $overlapped && str_starts_with($query->sql, 'select "event_key" from "notification_events"')) {
            $overlapped = true;
            DB::table('organizations')->where('id', $org)->update(['name' => 'Renamed']);
            $scanner->scanDates();
        }
    });
    $scanner->scanDates();
    expect($overlapped)->toBeTrue()->and(NotificationEvent::count())->toBe(1)
        ->and(NotificationEvent::sole()->variables['organization_name'])->toBe('Renamed');
});
