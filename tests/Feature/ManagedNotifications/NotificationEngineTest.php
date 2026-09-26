<?php

use App\Jobs\ResolveNotificationEventJob;
use App\Models\NotificationDefinition;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Services\ManagedNotifications\Channels\EmailChannel;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Services\ManagedNotifications\NotificationDefinitionManager;
use App\Services\ManagedNotifications\NotificationDispatcher;
use App\Services\ManagedNotifications\NotificationEventRecorder;
use App\Services\ManagedNotifications\NotificationResolver;
use App\Services\ManagedNotifications\NotificationTemplateRenderer;
use App\Services\ManagedNotifications\WebhookUrlValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('managed_notifications', require base_path('config/managed_notifications.php'));
    config()->set('admin_permissions', require base_path('config/admin_permissions.php'));
    config()->set('session.driver', 'array');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('managed_notifications.allowed_senders', ['alerts@example.com']);
    config()->set('managed_notifications.webhook_allowed_hosts', ['hooks.example.com']);

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->string('role');
        $table->integer('status')->default(1);
        $table->softDeletes();
    });
    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
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
    Schema::create('notification_logs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('organization_id');
        $table->string('notification_type');
        $table->json('payload');
        $table->json('fcm_response')->nullable();
        $table->timestamp('sent_at')->useCurrent();
        $table->boolean('delivered')->default(false);
        $table->boolean('clicked')->default(false);
        $table->timestamp('read_at')->nullable();
    });

    foreach ([
        '2026_09_26_000001_create_notification_definitions_table.php',
        '2026_09_26_000002_create_notification_events_table.php',
        '2026_09_26_000003_create_notification_deliveries_table.php',
    ] as $migration) {
        (require base_path('database/migrations/'.$migration))->up();
    }

    app(NotificationCatalog::class)->ensureDefinitions();
});

afterEach(function () {
    DB::disconnect('sqlite');
});

function managedNotificationValues(): array
{
    return [
        'organization_name' => 'Tenant A',
        'template_name' => 'order_update',
        'old_status' => 'PENDING',
        'new_status' => 'APPROVED',
    ];
}

function managedNotificationDefinitionInput(NotificationDefinition $definition, array $changes = []): array
{
    return array_merge([
        'revision' => $definition->revision,
        'enabled' => true,
        'delivery_mode' => 'dashboard_email',
        'dashboard_template' => $definition->dashboard_template,
        'email_subject' => $definition->email_subject,
        'email_body' => $definition->email_body,
        'sender_email' => 'alerts@example.com',
        'webhook_url' => null,
        'webhook_secret' => null,
    ], $changes);
}

function managedNotificationOrganization(string $name): int
{
    return DB::table('organizations')->insertGetId(['name' => $name]);
}

function managedNotificationUser(string $email, string $role): int
{
    return DB::table('users')->insertGetId(['email' => $email, 'role' => $role]);
}

function managedNotificationOwner(int $organizationId, int $userId): void
{
    DB::table('teams')->insert([
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'role' => 'owner',
        'status' => 'active',
    ]);
}

it('validates only predefined template variables, sender identities, and webhook hosts', function () {
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $manager = app(NotificationDefinitionManager::class);

    expect(fn () => $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'dashboard_template' => 'Unexpected {{password}}',
    ]), 1))->toThrow(InvalidArgumentException::class);

    expect(fn () => $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'sender_email' => 'unapproved@example.com',
    ]), 1))->toThrow(InvalidArgumentException::class);

    expect(fn () => $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://localhost/receive',
        'webhook_secret' => str_repeat('x', 32),
    ]), 1))->toThrow(InvalidArgumentException::class);

    $updated = $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://hooks.example.com/receive',
        'webhook_secret' => str_repeat('x', 32),
    ]), 1);
    expect($updated->revision)->toBe(2)
        ->and($updated->webhook_secret)->toBe(str_repeat('x', 32));
    expect(DB::table('notification_definitions')->where('id', $updated->id)->value('webhook_secret'))
        ->not->toBe(str_repeat('x', 32));
});

it('deduplicates events and deliveries while excluding other tenants', function () {
    $orgA = managedNotificationOrganization('Tenant A');
    $orgB = managedNotificationOrganization('Tenant B');
    $owner = managedNotificationUser('shared@example.com', 'user');
    $otherOwner = managedNotificationUser('shared@example.com', 'user');
    $outsider = managedNotificationUser('outsider@example.com', 'user');
    managedNotificationOwner($orgA, $owner);
    managedNotificationOwner($orgA, $otherOwner);
    managedNotificationOwner($orgB, $outsider);
    $admin = managedNotificationUser('admin@example.com', 'admin');
    managedNotificationOwner($orgA, $admin);

    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition), $admin);

    $recorder = app(NotificationEventRecorder::class);
    $first = $recorder->record($orgA, $definition->type_key, 'template:123:PENDING:APPROVED', managedNotificationValues());
    $again = $recorder->record($orgA, $definition->type_key, 'template:123:PENDING:APPROVED', managedNotificationValues());
    expect($again->id)->toBe($first->id);

    app(NotificationResolver::class)->resolve($first->id);
    app(NotificationResolver::class)->resolve($first->id);

    expect(NotificationEvent::count())->toBe(1)
        ->and(NotificationLog::count())->toBe(3)
        ->and(NotificationDelivery::where('channel', 'dashboard')->count())->toBe(3)
        ->and(NotificationDelivery::where('channel', 'email')->count())->toBe(2)
        ->and(NotificationLog::where('organization_id', $orgB)->count())->toBe(0);
});

it('cancels disabled definitions without contacting a channel', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $event = app(NotificationEventRecorder::class)->record(
        $org, 'meta.template_status_changed', 'disabled-event', managedNotificationValues()
    );

    app(NotificationResolver::class)->resolve($event->id);

    expect($event->fresh()->status)->toBe('canceled')
        ->and(NotificationDelivery::count())->toBe(0)
        ->and(NotificationLog::count())->toBe(0);
});

it('sends email through the queued delivery state and records success', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'email-event', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();

    Mail::shouldReceive('raw')->once();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->attempts)->toBe(1);
});

it('signs webhook payloads and records a successful response', function () {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, [
            'delivery_mode' => 'dashboard',
            'webhook_url' => 'https://hooks.example.com/receive',
            'webhook_secret' => str_repeat('s', 32),
        ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'webhook-event', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'webhook')->firstOrFail();

    $validator = Mockery::mock(WebhookUrlValidator::class);
    $validator->shouldReceive('validateResolvedAddress')->once()->andReturn('93.184.215.14');
    app()->instance(WebhookUrlValidator::class, $validator);
    Http::fake(['hooks.example.com/*' => Http::response([], 200)]);

    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('sent');
    Http::assertSent(fn ($request) =>
        $request->hasHeader('X-Chatberry-Idempotency-Key', 'notification-'.$event->id)
        && $request->hasHeader('X-Chatberry-Signature-256')
        && $request['organization_id'] === $org
    );
});

it('retries transient delivery failures and records the terminal failure', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'retry-event', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();

    $email = Mockery::mock(EmailChannel::class);
    $email->shouldReceive('send')->times(4)->andThrow(new RuntimeException('Transport unavailable'));
    app()->instance(EmailChannel::class, $email);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        NotificationDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subSecond()]);
        app(NotificationDispatcher::class)->deliver($delivery->id);
    }

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->attempts)->toBe(4)
        ->and($delivery->fresh()->last_error)->toContain('Transport unavailable');
});

it('queues pending outbox work on the existing default worker', function () {
    $org = managedNotificationOrganization('Tenant A');
    $event = app(NotificationEventRecorder::class)->record(
        $org, 'meta.template_status_changed', 'queued-event', managedNotificationValues()
    );
    Queue::fake();

    Artisan::call('notifications:reconcile');

    Queue::assertPushed(ResolveNotificationEventJob::class,
        fn ($job) => $job->eventId === $event->id && $job->queue === 'default');
});

it('rejects a reused source key carrying different event data', function () {
    $org = managedNotificationOrganization('Tenant A');
    $recorder = app(NotificationEventRecorder::class);
    $recorder->record($org, 'meta.template_status_changed', 'same-key', managedNotificationValues());

    expect(fn () => $recorder->record($org, 'meta.template_status_changed', 'same-key', array_merge(
        managedNotificationValues(), ['new_status' => 'REJECTED']
    )))->toThrow(InvalidArgumentException::class);
});

it('restricts definition writes to authorized platform admins', function () {
    $staffId = managedNotificationUser('staff@example.com', 'Viewer');
    $roleId = DB::table('roles')->insertGetId(['name' => 'Viewer']);
    DB::table('role_permissions')->insert(['role_id' => $roleId, 'module' => 'notifications', 'action' => 'view']);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();

    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($staffId), 'admin')
        ->put('/admin/settings/managed-notifications/'.$definition->type_key,
            managedNotificationDefinitionInput($definition))
        ->assertForbidden();

    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $this->actingAs(App\Models\User::findOrFail($adminId), 'admin')
        ->put('/admin/settings/managed-notifications/'.$definition->type_key,
            managedNotificationDefinitionInput($definition))
        ->assertRedirect();
    expect($definition->fresh()->enabled)->toBeTrue();
});

it('shows notification definitions and supported variables only to authorized admins', function () {
    $staffId = managedNotificationUser('staff@example.com', 'Viewer');
    $roleId = DB::table('roles')->insertGetId(['name' => 'Viewer']);
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($staffId), 'admin');

    $this->withHeader('X-Inertia', 'true')
        ->get('/admin/settings/managed-notifications')->assertForbidden();

    DB::table('role_permissions')->insert([
        'role_id' => $roleId, 'module' => 'notifications', 'action' => 'view',
    ]);
    $response = $this->get('/admin/settings/managed-notifications')->assertOk()
        ->assertJsonPath('component', 'Admin/Setting/ManagedNotifications')
        ->assertJsonCount(3, 'props.definitions');
    $definition = collect($response->json('props.definitions'))
        ->firstWhere('type_key', 'meta.template_status_changed');

    expect($definition['variables'])->toContain('template_name')
        ->and($definition['enabled'])->toBeFalse()
        ->and($definition['test_preview']['variables']['organization_name'])->toBe('Example Organization')
        ->and($definition['test_preview']['subject'])->toContain('[TEST]')
        ->and($definition['test_preview']['body'])->toContain('example_template')
        ->and($definition)->not->toHaveKey('webhook_secret');
});

it('saves admin settings atomically and rejects stale revisions', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');

    $input = managedNotificationDefinitionInput($definition, [
        'delivery_mode' => 'dashboard_email',
        'dashboard_template' => 'Template {{template_name}} is {{new_status}}.',
        'email_subject' => '{{template_name}} status',
        'email_body' => 'In {{organization_name}}, the status is {{new_status}}.',
        'webhook_url' => 'https://hooks.example.com/receive',
        'webhook_secret' => str_repeat('s', 32),
    ]);
    $this->put('/admin/settings/managed-notifications/'.$definition->type_key, $input)
        ->assertRedirect()->assertSessionHasNoErrors();

    $saved = $definition->fresh();
    expect($saved->enabled)->toBeTrue()
        ->and($saved->delivery_mode)->toBe('dashboard_email')
        ->and($saved->dashboard_template)->toBe($input['dashboard_template'])
        ->and($saved->email_subject)->toBe($input['email_subject'])
        ->and($saved->email_body)->toBe($input['email_body'])
        ->and($saved->sender_email)->toBe('alerts@example.com')
        ->and($saved->webhook_url)->toBe($input['webhook_url'])
        ->and($saved->webhook_secret)->toBe($input['webhook_secret'])
        ->and($saved->updated_by)->toBe($adminId)
        ->and($saved->revision)->toBe(2);

    $this->put('/admin/settings/managed-notifications/'.$definition->type_key, array_merge($input, [
        'enabled' => false,
        'dashboard_template' => 'Stale overwrite',
    ]))->assertRedirect()->assertSessionHasErrors('definition');
    expect($definition->fresh()->enabled)->toBeTrue()
        ->and($definition->fresh()->dashboard_template)->toBe($input['dashboard_template']);
});

it('validates admin input and rejects unknown notification types', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');

    $url = '/admin/settings/managed-notifications/'.$definition->type_key;
    $this->put($url, managedNotificationDefinitionInput($definition, [
        'delivery_mode' => 'sms',
    ]))->assertSessionHasErrors('delivery_mode');
    $this->put($url, managedNotificationDefinitionInput($definition, [
        'dashboard_template' => 'Hello {{unknown_variable}}',
    ]))->assertSessionHasErrors('definition');
    $this->put($url, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'http://localhost/receive',
        'webhook_secret' => str_repeat('s', 32),
    ]))->assertSessionHasErrors('definition');
    $this->put('/admin/settings/managed-notifications/unknown.type',
        managedNotificationDefinitionInput($definition))->assertNotFound();
    expect($definition->fresh()->revision)->toBe(1);
});

it('sends a rendered test email without creating a production notification event', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();

    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');

    Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) {
        expect($body)->toContain('Example Organization')->toContain('example_template');
        $message = Mockery::mock();
        $message->shouldReceive('from')->once()->with('alerts@example.com')->andReturnSelf();
        $message->shouldReceive('to')->once()->with('recipient@example.com')->andReturnSelf();
        $message->shouldReceive('subject')->once()->with('[TEST] WhatsApp template status: example_template')->andReturnSelf();
        $callback($message);
    });

    $this->post('/admin/settings/managed-notifications/'.$definition->type_key.'/test-email', [
        'sender_email' => 'alerts@example.com',
        'recipient_email' => 'recipient@example.com',
        'revision' => $definition->revision,
    ])->assertRedirect()->assertSessionHas('status.type', 'success');

    expect(NotificationEvent::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0)
        ->and(NotificationLog::count())->toBe(0);
});

it('reports test mail transport failure without creating a notification event', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP password=sensitive'));

    $response = $this->post('/admin/settings/managed-notifications/'.$definition->type_key.'/test-email', [
        'sender_email' => 'alerts@example.com',
        'recipient_email' => 'recipient@example.com',
        'revision' => $definition->revision,
    ])->assertRedirect()->assertSessionHas('status.type', 'error');

    expect($response->getSession()->get('status.message'))->not->toContain('sensitive')
        ->and(NotificationEvent::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0);
});

it('validates test sender, recipient, template revision, and permission', function () {
    $staffId = managedNotificationUser('staff@example.com', 'Viewer');
    $roleId = DB::table('roles')->insertGetId(['name' => 'Viewer']);
    DB::table('role_permissions')->insert([
        'role_id' => $roleId, 'module' => 'notifications', 'action' => 'view',
    ]);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $url = '/admin/settings/managed-notifications/'.$definition->type_key.'/test-email';
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($staffId), 'admin');
    $data = [
        'sender_email' => 'alerts@example.com',
        'recipient_email' => 'recipient@example.com',
        'revision' => $definition->revision,
    ];
    $this->post($url, $data)->assertForbidden();

    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $this->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    Mail::shouldReceive('raw')->never();
    $this->post($url, array_merge($data, ['sender_email' => 'unapproved@example.com']))
        ->assertSessionHasErrors('sender_email');
    $this->post($url, array_merge($data, ['recipient_email' => 'invalid']))
        ->assertSessionHasErrors('recipient_email');
    $this->post($url, array_merge($data, ['revision' => 999]))
        ->assertSessionHasErrors('test_email');
    $this->post('/admin/settings/managed-notifications/unknown.type/test-email', $data)
        ->assertNotFound();
    expect(NotificationEvent::count())->toBe(0);
});

it('keeps the settings page available when a stored test template cannot render safely', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    DB::table('notification_definitions')->where('id', $definition->id)
        ->update(['email_body' => 'Hello {{unknown_variable}}']);

    $response = $this->withHeader('X-Inertia', 'true')
        ->get('/admin/settings/managed-notifications')->assertOk();
    $preview = collect($response->json('props.definitions'))
        ->firstWhere('type_key', $definition->type_key)['test_preview'];
    expect($preview['error'])->toContain('unknown_variable');

    Mail::shouldReceive('raw')->never();
    $this->post('/admin/settings/managed-notifications/'.$definition->type_key.'/test-email', [
        'sender_email' => 'alerts@example.com',
        'recipient_email' => 'recipient@example.com',
        'revision' => $definition->revision,
    ])->assertSessionHasErrors('test_email');

    DB::table('notification_definitions')->where('id', $definition->id)
        ->update(['email_body' => $definition->email_body, 'email_subject' => str_repeat('S', 190)]);
    $response = $this->get('/admin/settings/managed-notifications')->assertOk();
    $preview = collect($response->json('props.definitions'))
        ->firstWhere('type_key', $definition->type_key)['test_preview'];
    expect($preview['error'])->toContain('subject');
});

it('cancels unsent email after the definition is disabled', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $definition = app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'disable-after-resolve', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['enabled' => false, 'delivery_mode' => 'email']), $owner);

    Mail::shouldReceive('raw')->never();
    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('canceled');
});

it('marks interrupted email uncertain and recovers an interrupted webhook', function () {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $event = NotificationEvent::create([
        'organization_id' => $org,
        'type_key' => $definition->type_key,
        'event_key' => hash('sha256', 'stale'),
        'variables' => managedNotificationValues(),
        'status' => 'resolved',
    ]);
    $email = NotificationDelivery::create([
        'event_id' => $event->id, 'organization_id' => $org, 'channel' => 'email',
        'recipient_key' => hash('sha256', 'email'), 'status' => 'processing',
        'claimed_at' => now()->subMinutes(6), 'attempts' => 1,
    ]);
    $webhook = NotificationDelivery::create([
        'event_id' => $event->id, 'organization_id' => $org, 'channel' => 'webhook',
        'recipient_key' => hash('sha256', 'webhook'), 'status' => 'processing',
        'claimed_at' => now()->subMinutes(6), 'attempts' => 1,
    ]);
    $exhaustedWebhook = NotificationDelivery::create([
        'event_id' => $event->id, 'organization_id' => $org, 'channel' => 'webhook',
        'recipient_key' => hash('sha256', 'exhausted-webhook'), 'status' => 'processing',
        'claimed_at' => now()->subMinutes(6),
        'attempts' => config('managed_notifications.max_attempts'),
    ]);
    Queue::fake();

    Artisan::call('notifications:reconcile');

    expect($email->fresh()->status)->toBe('uncertain')
        ->and($webhook->fresh()->status)->toBe('pending')
        ->and($exhaustedWebhook->fresh()->status)->toBe('failed');
});

it('does not send to an owner after their membership is suspended', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'suspended-owner', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();
    DB::table('teams')->where('organization_id', $org)->where('user_id', $owner)->update(['status' => 'suspended']);

    Mail::shouldReceive('raw')->never();
    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('canceled');
});

it('does not deliver to a disabled user account', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'disabled-account', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();
    DB::table('users')->where('id', $owner)->update(['status' => 0]);

    Mail::shouldReceive('raw')->never();
    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('canceled');
});

it('includes only staff explicitly allowed to receive and view platform alerts', function () {
    $org = managedNotificationOrganization('Tenant A');
    $allowedRole = DB::table('roles')->insertGetId(['name' => 'Alert operator']);
    DB::table('role_permissions')->insert([
        ['role_id' => $allowedRole, 'module' => 'notifications', 'action' => 'receive'],
        ['role_id' => $allowedRole, 'module' => 'notifications', 'action' => 'view'],
    ]);
    $receiveOnlyRole = DB::table('roles')->insertGetId(['name' => 'Hidden operator']);
    DB::table('role_permissions')->insert([
        'role_id' => $receiveOnlyRole, 'module' => 'notifications', 'action' => 'receive',
    ]);
    $allowed = managedNotificationUser('allowed@example.com', 'Alert operator');
    $hidden = managedNotificationUser('hidden@example.com', 'Hidden operator');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'dashboard']), $allowed);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'staff-permission', managedNotificationValues());

    app(NotificationResolver::class)->resolve($event->id);

    expect(NotificationLog::where('user_id', $allowed)->count())->toBe(1)
        ->and(NotificationLog::where('user_id', $hidden)->count())->toBe(0);
});

it('keeps the user inbox scoped to the selected organization and preserves chat alerts', function () {
    $orgA = managedNotificationOrganization('Tenant A');
    $orgB = managedNotificationOrganization('Tenant B');
    $userId = managedNotificationUser('owner@example.com', 'user');
    $newType = NotificationLog::create([
        'user_id' => $userId, 'organization_id' => $orgA,
        'notification_type' => 'meta.template_status_changed',
        'payload' => ['message' => 'Template changed'], 'delivered' => true,
    ]);
    NotificationLog::create([
        'user_id' => $userId, 'organization_id' => $orgA,
        'notification_type' => 'chat_assigned',
        'payload' => ['message' => 'Chat assigned'], 'delivered' => true,
    ]);
    $otherTenant = NotificationLog::create([
        'user_id' => $userId, 'organization_id' => $orgB,
        'notification_type' => 'meta.template_status_changed',
        'payload' => ['message' => 'Other tenant'], 'delivered' => true,
    ]);

    $this->withoutMiddleware()->actingAs(App\Models\User::findOrFail($userId), 'user')
        ->withSession(['current_organization' => $orgA])
        ->getJson('/notifications/unread-count')
        ->assertOk()->assertJsonPath('count', 2);

    $this->postJson('/notifications/'.$otherTenant->id.'/click')->assertNotFound();
    $this->postJson('/notifications/'.$newType->id.'/click')->assertOk();
    expect($otherTenant->fresh()->clicked)->toBeFalse();
});

it('rolls back the outbox event with the source transaction', function () {
    $org = managedNotificationOrganization('Tenant A');

    try {
        DB::transaction(function () use ($org) {
            app(NotificationEventRecorder::class)->record(
                $org, 'meta.template_status_changed', 'rolled-back-source', managedNotificationValues()
            );
            throw new RuntimeException('Source update failed');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Source update failed');
    }

    expect(NotificationEvent::count())->toBe(0);
});

it('leaves an event pending when queue dispatch is unavailable', function () {
    $org = managedNotificationOrganization('Tenant A');
    $event = app(NotificationEventRecorder::class)->record(
        $org, 'meta.template_status_changed', 'queue-unavailable', managedNotificationValues()
    );
    config()->set('managed_notifications.queue_connection', 'missing-connection');

    Artisan::call('notifications:reconcile');

    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->queued_at)->toBeNull();
});

it('records a permanent webhook rejection without retrying it', function () {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, [
            'webhook_url' => 'https://hooks.example.com/receive',
            'webhook_secret' => str_repeat('s', 32),
        ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'webhook-rejected', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'webhook')->firstOrFail();
    $validator = Mockery::mock(WebhookUrlValidator::class);
    $validator->shouldReceive('validateResolvedAddress')->once()->andReturn('93.184.215.14');
    app()->instance(WebhookUrlValidator::class, $validator);
    Http::fake(['hooks.example.com/*' => Http::response([], 400)]);

    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->last_error)->toContain('HTTP 400');
});

it('prunes old completed records while retaining failures and unread dashboard items', function () {
    $org = managedNotificationOrganization('Tenant A');
    $user = managedNotificationUser('owner@example.com', 'user');
    $type = 'meta.template_status_changed';
    $old = now()->subDays(200);
    $readLog = NotificationLog::create([
        'user_id' => $user, 'organization_id' => $org, 'notification_type' => $type,
        'payload' => ['message' => 'Read'], 'delivered' => true,
        'clicked' => true, 'read_at' => $old, 'sent_at' => $old,
    ]);
    DB::table('notification_logs')->where('id', $readLog->id)->update(['sent_at' => $old]);
    $done = NotificationEvent::create([
        'organization_id' => $org, 'type_key' => $type, 'event_key' => hash('sha256', 'done'),
        'variables' => managedNotificationValues(), 'status' => 'resolved', 'created_at' => $old,
    ]);
    NotificationDelivery::create([
        'event_id' => $done->id, 'organization_id' => $org, 'channel' => 'dashboard',
        'recipient_key' => hash('sha256', 'done'), 'status' => 'sent',
        'notification_log_id' => $readLog->id,
    ]);
    $failed = NotificationEvent::create([
        'organization_id' => $org, 'type_key' => $type, 'event_key' => hash('sha256', 'failed'),
        'variables' => managedNotificationValues(), 'status' => 'failed', 'created_at' => $old,
    ]);
    $unreadLog = NotificationLog::create([
        'user_id' => $user, 'organization_id' => $org, 'notification_type' => $type,
        'payload' => ['message' => 'Unread'], 'delivered' => true, 'clicked' => false,
    ]);
    DB::table('notification_logs')->where('id', $unreadLog->id)->update(['sent_at' => $old]);
    $unread = NotificationEvent::create([
        'organization_id' => $org, 'type_key' => $type, 'event_key' => hash('sha256', 'unread'),
        'variables' => managedNotificationValues(), 'status' => 'resolved', 'created_at' => $old,
    ]);
    NotificationDelivery::create([
        'event_id' => $unread->id, 'organization_id' => $org, 'channel' => 'dashboard',
        'recipient_key' => hash('sha256', 'unread'), 'status' => 'sent',
        'notification_log_id' => $unreadLog->id,
    ]);

    Artisan::call('notifications:prune');

    expect(NotificationEvent::whereKey($done->id)->exists())->toBeFalse()
        ->and(NotificationLog::whereKey($readLog->id)->exists())->toBeFalse()
        ->and(NotificationEvent::whereKey($failed->id)->exists())->toBeTrue()
        ->and(NotificationEvent::whereKey($unread->id)->exists())->toBeTrue()
        ->and(NotificationLog::whereKey($unreadLog->id)->exists())->toBeTrue();
});
