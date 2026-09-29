<?php

use App\Jobs\ResolveNotificationEventJob;
use App\Models\NotificationDefinition;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSender;
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
    Schema::create('addons', function (Blueprint $table) {
        $table->id();
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
    (require base_path('database/migrations/2026_09_27_000005_index_notification_inbox_order.php'))->up();
    (require base_path('database/migrations/2026_09_28_000003_optimize_notification_inbox_indexes.php'))->up();

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

it('synchronizes the notification catalog with one definition write', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(NotificationCatalog::class)->ensureDefinitions();
    $writes = collect(DB::getQueryLog())->filter(fn ($query) =>
        str_contains(strtolower($query['query']), 'insert')
        && str_contains(strtolower($query['query']), 'notification_definitions'));
    DB::disableQueryLog();

    expect($writes)->toHaveCount(1)
        ->and(NotificationDefinition::count())->toBe(count(config('managed_notifications.types')));
});

it('renders safe HTML email with inline styles, tables, links, images and escaped variables', function () {
    $template = '<!doctype html><html><body><div dir="rtl"><table cellpadding="8" style="width:100%;background-color:#ffffff">'
        .'<tr><td style="color:#123456"><strong>{{organization_name}}</strong></td></tr></table>'
        .'<a href="https://chatberry.net" onclick="alert(1)">Open Chatberry</a>'
        .'<img src="https://chatberry.net/logo.png" alt="Chatberry" onerror="alert(1)">'
        .'<a href="javascript:alert(1)">Unsafe link</a><script>alert(1)</script></div></body></html>';
    $name = 'شركة <img src=x onerror=alert(2)> & "العميل"';
    $body = app(NotificationTemplateRenderer::class)->renderEmail($template,
        ['organization_name' => $name], ['organization_name']);

    expect($body['html'])->toContain('<table', 'cellpadding="8"', 'color:#123456', 'dir="rtl"',
        'href="https://chatberry.net"', 'src="https://chatberry.net/logo.png"', '&lt;img')
        ->not->toContain('<script', 'onclick=', 'onerror="', 'javascript:', '<img src=x')
        ->and($body['text'])->toContain($name, 'Open Chatberry')->not->toContain('<table', '<strong>');
});

it('preserves plain email text and line breaks without treating variable values as HTML', function () {
    $template = "Contact <support@example.com>\nHello {{organization_name}}\nUsage < 70% & growing";
    $name = '<strong>Tenant A</strong>';
    $body = app(NotificationTemplateRenderer::class)->renderEmail($template,
        ['organization_name' => $name], ['organization_name']);

    expect($body['text'])->toBe(str_replace('{{organization_name}}', $name, $template))
        ->and($body['html'])->toContain('&lt;support@example.com&gt;', '&lt;strong&gt;Tenant A&lt;/strong&gt;', '<br>', '&amp; growing')
        ->not->toContain('<strong>Tenant A</strong>');
});

it('validates HTML variables before escaping and removes unsafe attribute values', function () {
    $renderer = app(NotificationTemplateRenderer::class);
    $body = $renderer->renderEmail('<p>{{organization_name}}</p>',
        ['organization_name' => str_repeat('&', 500)], ['organization_name']);
    expect($body['text'])->toBe(str_repeat('&', 500));

    $body = $renderer->renderEmail('<a href="{{organization_name}}">Open</a>',
        ['organization_name' => 'javascript:alert(1)'], ['organization_name']);
    expect($body['html'])->not->toContain('javascript:');
    expect(fn () => $renderer->renderEmail('<p>{{password}}</p>',
        ['organization_name' => 'Tenant A'], ['organization_name']))->toThrow(InvalidArgumentException::class);
});

it('converts subject HTML to plain text while preserving predefined variables', function ($template, $expected) {
    $renderer = app(NotificationTemplateRenderer::class);
    $allowed = ['template_name'];
    expect($renderer->normalizeEmailSubject($template, $allowed))->toBe($expected)
        ->and($renderer->renderEmailSubject($template, ['template_name' => 'order_update'], $allowed))
        ->toBe(str_replace('{{template_name}}', 'order_update', $expected));
})->with([
    'Arabic HTML' => ['<strong>تنبيه</strong>: {{template_name}} &amp; الرسائل', 'تنبيه: {{template_name}} & الرسائل'],
    'blocks' => ['<p>Template</p><p>{{template_name}}</p>', 'Template {{template_name}}'],
    'unsafe markup' => ['<script>alert(1)</script><b onclick="alert(2)">{{template_name}}</b>', '{{template_name}}'],
    'existing plain text' => ['Usage < 70% & growing: {{template_name}}', 'Usage < 70% & growing: {{template_name}}'],
]);

it('rejects empty, oversized, unknown-variable and injected subjects', function () {
    $renderer = app(NotificationTemplateRenderer::class);
    foreach (['<img src="https://chatberry.net/logo.png">', '<b>'.str_repeat('x', 192).'</b>',
        '<b>{{password}}</b>', "Hello\r\nBcc: other@example.com", '<b>Hello&#13;&#10;Bcc: other@example.com</b>',
        "Hello\0"] as $template) {
        expect(fn () => $renderer->normalizeEmailSubject($template, ['template_name']))
            ->toThrow(InvalidArgumentException::class);
    }
    foreach (["order\r\nBcc: other@example.com", str_repeat('x', 192)] as $value) {
        expect(fn () => $renderer->renderEmailSubject('<b>{{template_name}}</b>',
            ['template_name' => $value], ['template_name']))->toThrow(InvalidArgumentException::class);
    }
    expect($renderer->normalizeEmailSubject('Hello&#0;', ['template_name']))->toBe('Hello&#0;');
});

it('accepts subject HTML longer than the database field and saves its short plain text safely', function () {
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $url = '/admin/settings/managed-notifications/'.$definition->type_key;
    $this->put($url, managedNotificationDefinitionInput($definition, [
        'email_subject' => '<strong title="'.str_repeat('x', 250).'">تنبيه</strong>: {{template_name}}',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $definition->refresh();
    expect($definition->email_subject)->toBe('تنبيه: {{template_name}}');
    foreach (['<b>'.str_repeat('x', 192).'</b>', '<b>{{unknown_variable}}</b>', '<script>alert(1)</script>'] as $subject) {
        $this->put($url, managedNotificationDefinitionInput($definition, ['email_subject' => $subject]))
            ->assertSessionHasErrors('definition');
    }
    expect($definition->fresh()->email_subject)->toBe('تنبيه: {{template_name}}');
});

it('delivers an HTML notification as multipart email and preserves its snapshot on retry', function () {
    config()->set('mail.default', 'array');
    $organizationId = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($organizationId, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $manager = app(NotificationDefinitionManager::class);
    $definition = $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'email_subject' => '<strong>Status:</strong> {{template_name}}',
        'email_body' => '<p dir="rtl">مرحبًا <strong>{{organization_name}}</strong><br>{{template_name}}</p>',
    ]), $owner);
    $values = managedNotificationValues();
    $values['organization_name'] = 'شركة <img src=x onerror=alert(1)>';
    $event = app(NotificationEventRecorder::class)->record($organizationId, $definition->type_key, 'html-delivery', $values);
    app(NotificationResolver::class)->resolve($event->id);
    $snapshot = $event->fresh()->rendered;
    $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'email_body' => '<p>Later edit</p>',
    ]), $owner);

    $messages = [];
    app('events')->listen(Illuminate\Mail\Events\MessageSending::class, function ($mail) use (&$messages) {
        $messages[] = $mail->message;
    });
    $delivery = NotificationDelivery::where('channel', 'email')->firstOrFail();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    app(NotificationDispatcher::class)->deliver($delivery->id);
    app(NotificationResolver::class)->resolve($event->id);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getSubject())->toBe('Status: order_update')
        ->and($messages[0]->getHtmlBody())->toBe($snapshot['email_html'])
        ->and($messages[0]->getTextBody())->toBe($snapshot['email'])
        ->and($messages[0]->getHtmlBody())->toContain('<strong>', '&lt;img')->not->toContain('<img src=x', 'Later edit')
        ->and($messages[0]->toString())->toContain('multipart/alternative', 'text/html', 'text/plain')
        ->and($delivery->fresh()->status)->toBe('sent')
        ->and(NotificationDelivery::where('channel', 'email')->count())->toBe(1)
        ->and(NotificationLog::count())->toBe(1);
});

it('keeps previously resolved notification email snapshots as plain text', function () {
    config()->set('mail.default', 'array');
    $organizationId = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($organizationId, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($organizationId, $definition->type_key, 'legacy-text', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $snapshot = $event->fresh()->rendered;
    unset($snapshot['email_html']);
    $snapshot['email'] = '<p>Existing literal text</p>';
    $event->update(['rendered' => $snapshot]);
    $messages = [];
    app('events')->listen(Illuminate\Mail\Events\MessageSending::class, function ($mail) use (&$messages) {
        $messages[] = $mail->message;
    });

    app(NotificationDispatcher::class)->deliver(NotificationDelivery::where('channel', 'email')->firstOrFail()->id);
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getHtmlBody())->toBeNull()
        ->and($messages[0]->getTextBody())->toBe('<p>Existing literal text</p>');
});

it('previews and sends the saved HTML test template without creating notification records', function () {
    config()->set('mail.default', 'array');
    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $url = '/admin/settings/managed-notifications/'.$definition->type_key;
    $this->put($url, managedNotificationDefinitionInput($definition, [
        'email_subject' => '<strong>{{template_name}}</strong> &amp; {{organization_name}}',
        'email_body' => '<p style="color:#123456">Hello <strong>{{organization_name}}</strong></p><script>alert(1)</script>',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $definition->refresh();
    expect($definition->email_subject)->toBe('{{template_name}} & {{organization_name}}');
    $page = $this->withHeader('X-Inertia', 'true')->get('/admin/settings/managed-notifications')->assertOk();
    $preview = collect($page->json('props.definitions'))->firstWhere('type_key', $definition->type_key)['test_preview'];
    expect($preview['html'])->toContain('<strong>Example Organization</strong>', 'color:#123456')
        ->not->toContain('<script>')
        ->and($preview['body'])->toContain('Hello Example Organization')->not->toContain('<strong>')
        ->and($preview['subject'])->toBe('[TEST] example_template & Example Organization');

    $messages = [];
    app('events')->listen(Illuminate\Mail\Events\MessageSending::class, function ($mail) use (&$messages) {
        $messages[] = $mail->message;
    });
    $this->post($url.'/test-email', [
        'sender_email' => 'alerts@example.com', 'recipient_email' => 'recipient@example.com',
        'revision' => $definition->revision,
    ])->assertRedirect()->assertSessionHas('status.type', 'success');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getSubject())->toBe($preview['subject'])
        ->and($messages[0]->getHtmlBody())->toBe($preview['html'])
        ->and($messages[0]->getTextBody())->toBe($preview['body'])
        ->and($messages[0]->getTo()[0]->getAddress())->toBe('recipient@example.com')
        ->and(NotificationEvent::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0)
        ->and(NotificationLog::count())->toBe(0);
});

it('records events without synchronizing definitions on the webhook path', function () {
    $organizationId = managedNotificationOrganization('Tenant A');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $event = app(NotificationEventRecorder::class)->record(
        $organizationId, 'meta.template_status_changed', 'template:123:approved', managedNotificationValues()
    );
    $definitionQueries = collect(DB::getQueryLog())->filter(fn ($query) =>
        str_contains(strtolower($query['query']), 'notification_definitions')
        || str_contains(strtolower($query['query']), 'notification_email_senders'));
    DB::disableQueryLog();

    expect($event->exists)->toBeTrue()
        ->and($definitionQueries)->toBeEmpty();
});

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

    Mail::shouldReceive('send')->once();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    $eventReads = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "notification_events"'));
    DB::disableQueryLog();
    expect($eventReads)->toHaveCount(1);
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

it('leases outbox work before dispatch so overlapping reconcilers do not queue it twice', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $resolved = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'resolved', managedNotificationValues());
    app(NotificationResolver::class)->resolve($resolved->id);
    $pending = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'pending', managedNotificationValues());
    $dispatched = [];
    Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) use (&$dispatched) {
        $dispatched[] = get_class($job);
        if (count($dispatched) === 1) {
            Artisan::call('notifications:reconcile');
        }
    });
    Artisan::call('notifications:reconcile');
    expect($dispatched)->toHaveCount(2)
        ->and(array_unique($dispatched))->toHaveCount(2)
        ->and($pending->fresh()->queued_at)->not->toBeNull()
        ->and(NotificationDelivery::where('channel', 'email')->sole()->queued_at)->not->toBeNull();
});

it('releases a failed queue dispatch lease and can retry immediately', function () {
    $org = managedNotificationOrganization('Tenant A');
    $event = app(NotificationEventRecorder::class)->record($org, 'meta.template_status_changed',
        'queue-failure', managedNotificationValues());
    $attempts = 0;
    Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new RuntimeException('Simulated queue outage.');
        }
    });
    Artisan::call('notifications:reconcile');
    expect($event->fresh()->queued_at)->toBeNull();
    Artisan::call('notifications:reconcile');
    expect($event->fresh()->queued_at)->not->toBeNull()
        ->and($event->fresh()->status)->toBe('pending');
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
    $this->get('/admin/settings/notification-senders')->assertForbidden();
    $this->get('/admin/settings/notification-deliveries')->assertForbidden();

    DB::table('role_permissions')->insert([
        'role_id' => $roleId, 'module' => 'notifications', 'action' => 'view',
    ]);
    $response = $this->get('/admin/settings/managed-notifications')->assertOk()
        ->assertJsonPath('component', 'Admin/Setting/ManagedNotifications')
        ->assertJsonCount(count(config('managed_notifications.types')), 'props.definitions');
    expect($response->json('props'))->not->toHaveKey('deliveries')
        ->not->toHaveKey('senderAccounts');
    $this->get('/admin/settings/notification-senders')->assertOk()
        ->assertJsonPath('component', 'Admin/Setting/NotificationSenders')
        ->assertJsonCount(0, 'props.senderAccounts');

    $organizationId = managedNotificationOrganization('Tenant A');
    $eventId = DB::table('notification_events')->insertGetId([
        'organization_id' => $organizationId,
        'type_key' => 'meta.template_rejected',
        'event_key' => hash('sha256', 'delivery-history-test'),
        'variables' => json_encode(['organization_name' => 'Tenant A', 'template_name' => 'sample']),
        'status' => 'resolved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $deliveryId = DB::table('notification_deliveries')->insertGetId([
        'event_id' => $eventId,
        'organization_id' => $organizationId,
        'channel' => 'dashboard',
        'recipient_key' => hash('sha256', 'delivery-history-recipient'),
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $deliveryResponse = $this->get('/admin/settings/notification-deliveries')->assertOk()
        ->assertJsonPath('component', 'Admin/Setting/NotificationDeliveries')
        ->assertJsonPath('props.deliveries.data.0.id', $deliveryId)
        ->assertJsonPath('props.deliveries.data.0.type_key', 'meta.template_rejected');
    expect($deliveryResponse->json('props.deliveries'))->not->toHaveKey('total');

    foreach (range(1, 25) as $index) {
        DB::table('notification_deliveries')->insert([
            'event_id' => $eventId,
            'organization_id' => $organizationId,
            'channel' => 'dashboard',
            'recipient_key' => hash('sha256', 'delivery-history-recipient-'.$index),
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $firstPage = $this->get('/admin/settings/notification-deliveries')->assertOk()
        ->assertJsonCount(25, 'props.deliveries.data');
    $nextPageUrl = $firstPage->json('props.deliveries.next_page_url');
    expect($nextPageUrl)->not->toBeNull();
    $this->get('/admin/settings/notification-deliveries?'.parse_url($nextPageUrl, PHP_URL_QUERY))
        ->assertOk()->assertJsonCount(1, 'props.deliveries.data')
        ->assertJsonPath('props.deliveries.data.0.id', $deliveryId);

    expect(collect($response->json('props.definitions'))->pluck('type_key')->all())
        ->toContain('meta.business_capability_changed')
        ->not->toContain('meta.messaging_limit_increased', 'meta.messaging_limit_reduced', 'meta.phone_status_changed', 'meta.waba_reinstated');
    $definition = collect($response->json('props.definitions'))
        ->firstWhere('type_key', 'meta.template_status_changed');
    $accountAlert = collect($response->json('props.definitions'))
        ->firstWhere('type_key', 'meta.waba_disabled_flagged');
    $descriptions = collect($response->json('props.definitions'))->pluck('description');

    expect($definition['variables'])->toContain('template_name')
        ->and($descriptions->every(fn ($description) => is_string($description) && $description !== ''))->toBeTrue()
        ->and($accountAlert['description'])->toContain('flags or disables')
        ->and($definition['enabled'])->toBeFalse()
        ->and($definition['test_preview']['variables']['organization_name'])->toBe('Example Organization')
        ->and($definition['test_preview']['subject'])->toContain('[TEST]')
        ->and($definition['test_preview']['body'])->toContain('example_template')
        ->and($definition)->not->toHaveKey('webhook_secret');
});

it('lets authorized admins add and update a separate notification SMTP account without exposing its password', function () {
    $staffId = managedNotificationUser('staff@example.com', 'Viewer');
    $roleId = DB::table('roles')->insertGetId(['name' => 'Viewer']);
    DB::table('role_permissions')->insert(['role_id' => $roleId, 'module' => 'notifications', 'action' => 'view']);
    $this->withoutMiddleware([
        App\Http\Middleware\CheckAppStatus::class,
        App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class,
        App\Http\Middleware\Localization::class,
        App\Http\Middleware\VerifyCsrfToken::class,
    ])->actingAs(App\Models\User::findOrFail($staffId), 'admin');

    $url = '/admin/settings/notification-senders';
    $input = [
        'email' => ' Second@Example.com ',
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'second@example.com',
        'password' => 'secret-second-account',
    ];
    $this->post($url, $input)->assertForbidden();

    $adminId = managedNotificationUser('admin@example.com', 'admin');
    $this->actingAs(App\Models\User::findOrFail($adminId), 'admin');
    $this->post($url, array_merge($input, ['port' => 70000]))->assertSessionHasErrors('port');
    $this->post($url, array_merge($input, ['encryption' => 'none']))->assertSessionHasErrors('encryption');
    $this->post($url, array_merge($input, ['password' => '']))->assertSessionHasErrors('password');
    $this->post($url, $input)->assertRedirect();
    $sender = NotificationEmailSender::firstOrFail();
    expect($sender->email)->toBe('second@example.com')
        ->and($sender->password)->toBe('secret-second-account')
        ->and(DB::table('notification_email_senders')->value('password'))->not->toContain('secret-second-account')
        ->and(app(NotificationCatalog::class)->approvedSenders())->toContain('second@example.com');

    $page = $this->withHeader('X-Inertia', 'true')->get($url)->assertOk()
        ->assertJsonPath('component', 'Admin/Setting/NotificationSenders');
    expect($page->json('props.senderAccounts.0.email'))->toBe('second@example.com')
        ->and($page->json('props.senderAccounts.0'))->not->toHaveKey('password');
    $managedPage = $this->get('/admin/settings/managed-notifications')->assertOk();
    expect($managedPage->json('props'))->not->toHaveKey('senderAccounts')
        ->and($managedPage->json('props.senders'))->toContain('second@example.com');

    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $this->put('/admin/settings/managed-notifications/'.$definition->type_key,
        managedNotificationDefinitionInput($definition, ['sender_email' => 'second@example.com']))
        ->assertRedirect();
    expect($definition->fresh()->sender_email)->toBe('second@example.com');

    $this->actingAs(App\Models\User::findOrFail($staffId), 'admin')
        ->put($url.'/'.$sender->id, [
            'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'new-user', 'password' => '',
        ])->assertForbidden();
    $this->actingAs(App\Models\User::findOrFail($adminId), 'admin');

    $this->post($url, $input)->assertSessionHasErrors('email');
    $this->put($url.'/'.$sender->id, [
        'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl',
        'username' => 'new-user', 'password' => '',
    ])->assertRedirect();
    expect($sender->fresh()->host)->toBe('smtp2.example.com')
        ->and($sender->fresh()->password)->toBe('secret-second-account');

    $this->put($url.'/'.$sender->id, [
        'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl',
        'username' => 'new-user', 'password' => 'replacement-secret',
    ])->assertRedirect();
    expect($sender->fresh()->password)->toBe('replacement-secret');
    expect(NotificationEvent::count())->toBe(0);
});

it('uses the selected account transport for notification email without changing the default mailer', function () {
    NotificationEmailSender::create([
        'email' => 'second@example.com', 'host' => 'smtp.second.example',
        'port' => 465, 'encryption' => 'ssl', 'username' => 'smtp-user',
        'password' => 'smtp-secret',
    ]);
    $defaultMailer = config('mail.default');
    $manager = Mockery::mock(Illuminate\Mail\MailManager::class, [app()])->makePartial();
    $manager->shouldReceive('createSymfonyTransport')->once()->andReturnUsing(function ($config) {
        expect($config['host'])->toBe('smtp.second.example')
            ->and($config['scheme'])->toBe('smtps')
            ->and($config['username'])->toBe('smtp-user')
            ->and($config['password'])->toBe('smtp-secret');

        return new Symfony\Component\Mailer\Transport\NullTransport();
    });
    app()->instance('mail.manager', $manager);
    $messages = [];
    app('events')->listen(Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$messages) {
        $messages[] = $event->message;
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(EmailChannel::class)->sendMessage('second@example.com', 'recipient@example.com', 'Test', 'Body', '<p><strong>Body</strong></p>');
    $senderQueries = collect(DB::getQueryLog())->filter(fn ($query) =>
        str_contains(strtolower($query['query']), 'notification_email_senders'));
    DB::disableQueryLog();

    expect($messages)->toHaveCount(1)
        ->and($senderQueries)->toHaveCount(1)
        ->and($messages[0]->getFrom()[0]->getAddress())->toBe('second@example.com')
        ->and($messages[0]->getTo()[0]->getAddress())->toBe('recipient@example.com')
        ->and($messages[0]->getHtmlBody())->toBe('<p><strong>Body</strong></p>')
        ->and($messages[0]->getTextBody())->toBe('Body')
        ->and(config('mail.default'))->toBe($defaultMailer);
});

it('removes obsolete notification definitions and cancels their pending work', function ($type, $migration) {
    $organizationId = managedNotificationOrganization('Tenant A');
    $definition = (array) DB::table('notification_definitions')
        ->where('type_key', 'meta.business_capability_changed')->first();
    unset($definition['id']);
    $definition['type_key'] = $type;
    DB::table('notification_definitions')->insert($definition);

    $eventIds = [];
    foreach (['pending', 'resolved'] as $status) {
        $eventIds[$status] = DB::table('notification_events')->insertGetId([
            'organization_id' => $organizationId,
            'type_key' => $type,
            'event_key' => hash('sha256', $status),
            'variables' => json_encode(['organization_name' => 'Tenant A']),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $deliveryId = DB::table('notification_deliveries')->insertGetId([
        'event_id' => $eventIds['resolved'],
        'organization_id' => $organizationId,
        'channel' => 'webhook',
        'recipient_key' => hash('sha256', 'test-webhook'),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (require base_path('database/migrations/'.$migration))->up();
    app(NotificationCatalog::class)->ensureDefinitions();

    expect(config('managed_notifications.types'))->not->toHaveKey($type)
        ->and(NotificationDefinition::where('type_key', $type)->exists())->toBeFalse()
        ->and(NotificationEvent::findOrFail($eventIds['pending'])->status)->toBe('canceled')
        ->and(NotificationEvent::findOrFail($eventIds['resolved'])->status)->toBe('resolved')
        ->and(NotificationDelivery::findOrFail($deliveryId)->status)->toBe('canceled');
})->with([
    'phone status changed' => ['meta.phone_status_changed', '2026_09_27_000002_remove_phone_status_changed_notification.php'],
    'WABA reinstated' => ['meta.waba_reinstated', '2026_09_27_000003_remove_waba_reinstated_notification.php'],
]);

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
        'email_body' => '<p>In <strong>{{organization_name}}</strong>, the status is {{new_status}}.</p>',
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

    Mail::shouldReceive('send')->once()->andReturnUsing(function ($views, $data, $callback) {
        expect($views['raw'])->toContain('Example Organization')->toContain('example_template')
            ->and((string) $views['html'])->toContain('Example Organization')->toContain('example_template');
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
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP password=sensitive'));

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
    Mail::shouldReceive('send')->never();
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
    Mail::shouldReceive('send')->never();
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
    Mail::shouldReceive('send')->never();
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
    $replacementOwner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $replacementOwner);
    DB::table('teams')->where('organization_id', $org)->where('user_id', $owner)->update(['status' => 'suspended']);

    Mail::shouldReceive('raw')->never();
    Mail::shouldReceive('send')->never();
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
    Mail::shouldReceive('send')->never();
    app(NotificationDispatcher::class)->deliver($delivery->id);

    expect($delivery->fresh()->status)->toBe('canceled');
});

it('delivers managed dashboard and email notifications only to organization owners', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $admin = managedNotificationUser('admin@example.com', 'admin');
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
        managedNotificationDefinitionInput($definition), $allowed);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'staff-permission', managedNotificationValues());

    app(NotificationResolver::class)->resolve($event->id);

    expect(NotificationLog::where('user_id', $owner)->count())->toBe(1)
        ->and(NotificationLog::where('user_id', $admin)->count())->toBe(0)
        ->and(NotificationLog::where('user_id', $allowed)->count())->toBe(0)
        ->and(NotificationLog::where('user_id', $hidden)->count())->toBe(0)
        ->and(NotificationDelivery::where('channel', 'email')->get()->pluck('destination')->all())
        ->toBe(['owner@example.com']);
});

it('shows a persistent managed alert to its owner and records dismissal within the current tenant', function () {
    $orgA = managedNotificationOrganization('Tenant A');
    $orgB = managedNotificationOrganization('Tenant B');
    $owner = managedNotificationUser('owner@example.com', 'user');
    $staff = managedNotificationUser('staff@example.com', 'user');
    managedNotificationOwner($orgA, $owner);
    DB::table('teams')->insert([
        'organization_id' => $orgA, 'user_id' => $staff, 'role' => 'agent', 'status' => 'active',
    ]);
    $first = NotificationLog::create([
        'user_id' => $owner, 'organization_id' => $orgA,
        'notification_type' => 'meta.template_disabled',
        'payload' => ['message' => 'Template disabled'], 'delivered' => true,
    ]);
    $second = NotificationLog::create([
        'user_id' => $owner, 'organization_id' => $orgA,
        'notification_type' => 'meta.template_paused',
        'payload' => ['message' => 'Template paused'], 'delivered' => true,
    ]);
    $otherTenant = NotificationLog::create([
        'user_id' => $owner, 'organization_id' => $orgB,
        'notification_type' => 'meta.template_disabled',
        'payload' => ['message' => 'Other tenant'], 'delivered' => true,
    ]);
    $legacyStaff = NotificationLog::create([
        'user_id' => $staff, 'organization_id' => $orgA,
        'notification_type' => 'chat_assigned',
        'payload' => ['message' => 'Chat assigned'], 'delivered' => true,
    ]);
    NotificationLog::create([
        'user_id' => $staff, 'organization_id' => $orgA,
        'notification_type' => 'meta.template_disabled',
        'payload' => ['message' => 'Old staff alert'], 'delivered' => true,
    ]);

    $this->withoutMiddleware()->actingAs(App\Models\User::findOrFail($staff), 'user')
        ->withSession(['current_organization' => $orgA]);
    $this->getJson('/notifications/managed-unread')->assertOk()->assertJsonPath('notification', null);
    $this->getJson('/notifications/unread-count')->assertJsonPath('count', 1);
    $this->postJson('/notifications/'.$first->id.'/click')->assertNotFound();
    $this->withHeader('X-Inertia', 'true')->get('/notifications')->assertOk()
        ->assertJsonCount(1, 'props.notifications.data')
        ->assertJsonPath('props.notifications.data.0.id', $legacyStaff->id);
    expect($legacyStaff->fresh()->clicked)->toBeFalse();

    $this->actingAs(App\Models\User::findOrFail($owner), 'user');
    $this->getJson('/notifications/managed-unread')->assertOk()
        ->assertJsonPath('notification.id', $first->id)
        ->assertJsonPath('notification.message', 'Template disabled');
    $this->postJson('/notifications/'.$otherTenant->id.'/click')->assertNotFound();
    $this->postJson('/notifications/'.$first->id.'/click')->assertOk();
    expect($first->fresh()->clicked)->toBeTrue()
        ->and($first->fresh()->read_at)->not->toBeNull()
        ->and($otherTenant->fresh()->clicked)->toBeFalse();
    $this->getJson('/notifications/managed-unread')->assertJsonPath('notification.id', $second->id);
    DB::table('teams')->where('organization_id', $orgA)->where('user_id', $owner)
        ->update(['status' => 'suspended']);
    $this->getJson('/notifications/managed-unread')->assertJsonPath('notification', null);
    $this->postJson('/notifications/'.$second->id.'/click')->assertNotFound();
});

it('keeps the user inbox scoped to the selected organization and preserves chat alerts', function () {
    $orgA = managedNotificationOrganization('Tenant A');
    $orgB = managedNotificationOrganization('Tenant B');
    $userId = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($orgA, $userId);
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

it('paginates full notification messages in stable order without mixing tenants', function () {
    $orgA = managedNotificationOrganization('Tenant A');
    $orgB = managedNotificationOrganization('Tenant B');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($orgA, $owner);
    $ids = [];
    foreach (range(1, 23) as $number) {
        $ids[] = NotificationLog::create([
            'user_id' => $owner, 'organization_id' => $orgA,
            'notification_type' => 'meta.template_disabled',
            'payload' => ['message' => 'Alert '.$number], 'delivered' => true,
        ])->id;
    }
    NotificationLog::create([
        'user_id' => $owner, 'organization_id' => $orgB,
        'notification_type' => 'meta.template_disabled',
        'payload' => ['message' => 'Other tenant'], 'delivered' => true,
    ]);

    $this->withoutMiddleware()->actingAs(App\Models\User::findOrFail($owner), 'user')
        ->withSession(['current_organization' => $orgA])
        ->withHeader('X-Inertia', 'true');
    $this->get('/notifications')->assertOk()
        ->assertJsonCount(20, 'props.notifications.data')
        ->assertJsonPath('props.notifications.data.0.id', end($ids))
        ->assertJsonPath('props.notifications.data.0.payload.message', 'Alert 23')
        ->assertJsonPath('props.notifications.total', 23);
    $this->get('/notifications?page=2')->assertOk()
        ->assertJsonCount(3, 'props.notifications.data')
        ->assertJsonPath('props.notifications.data.2.id', $ids[0]);
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

it('prunes multiple batches past retained records without skipping completed notifications', function () {
    $org = managedNotificationOrganization('Tenant A');
    $user = managedNotificationUser('owner@example.com', 'user');
    $type = 'meta.template_status_changed';
    $old = now()->subDays(200)->toDateTimeString();
    $logs = $events = [];
    for ($i = 0; $i < 1002; $i++) {
        $logs[] = [
            'user_id' => $user, 'organization_id' => $org, 'notification_type' => $type,
            'payload' => json_encode(['message' => 'Message '.$i]), 'sent_at' => $old,
            'clicked' => $i !== 0,
        ];
        $events[] = [
            'organization_id' => $org, 'type_key' => $type, 'event_key' => hash('sha256', 'batch-'.$i),
            'variables' => '{}', 'status' => $i === 0 ? 'failed' : 'canceled',
            'created_at' => $old, 'updated_at' => $old,
        ];
    }
    foreach (array_chunk($logs, 100) as $batch) {
        DB::table('notification_logs')->insert($batch);
    }
    foreach (array_chunk($events, 100) as $batch) {
        DB::table('notification_events')->insert($batch);
    }
    $this->artisan('notifications:prune')->assertSuccessful();
    expect(NotificationLog::count())->toBe(1)
        ->and(NotificationLog::sole()->clicked)->toBeFalse()
        ->and(NotificationEvent::count())->toBe(1)
        ->and(NotificationEvent::sole()->status)->toBe('failed');
});

it('reverses and reapplies inbox indexes without removing notification data', function () {
    $org = managedNotificationOrganization('Tenant A');
    $user = managedNotificationUser('owner@example.com', 'user');
    NotificationLog::create([
        'user_id' => $user, 'organization_id' => $org, 'notification_type' => 'meta.template_status_changed',
        'payload' => ['message' => 'Preserved'],
    ]);
    $migration = require base_path('database/migrations/2026_09_28_000003_optimize_notification_inbox_indexes.php');
    $migration->up();
    $migration->down();
    expect(Schema::hasIndex('notification_logs', 'idx_notification_logs_user_org_type_sent'))->toBeTrue();
    $migration->up();
    expect(Schema::hasIndex('notification_logs', 'notification_logs_inbox_order'))->toBeTrue()
        ->and(Schema::hasIndex('notification_logs', 'notification_logs_popup_order'))->toBeTrue()
        ->and(Schema::hasIndex('notification_logs', 'idx_notification_logs_user_org_type_sent'))->toBeFalse()
        ->and(NotificationLog::sole()->payload['message'])->toBe('Preserved');
});

it('cancels unsent email when the admin changes the mode to dashboard only', function () {
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $manager = app(NotificationDefinitionManager::class);
    $definition = $manager->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'email']), $owner);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'mode-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'email')->sole();
    $manager->update($definition->type_key,
        managedNotificationDefinitionInput($definition, ['delivery_mode' => 'dashboard']), $owner);
    Mail::shouldReceive('send')->never();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('canceled');
});

it('cancels the previous queued webhook when its configuration is removed or changed', function (string $change) {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $manager = app(NotificationDefinitionManager::class);
    $definition = $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://hooks.example.com/old', 'webhook_secret' => str_repeat('s', 32),
    ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'webhook-config-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', 'webhook')->sole();
    $input = match ($change) {
        'removed' => ['webhook_url' => null],
        'url' => ['webhook_url' => 'https://hooks.example.com/new'],
        'secret' => ['webhook_secret' => str_repeat('n', 32)],
    };
    $manager->update($definition->type_key, managedNotificationDefinitionInput($definition, $input), 1);
    Http::fake();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe('canceled');
})->with(['removed', 'url', 'secret']);

it('retries a temporary webhook DNS failure instead of marking it permanently failed', function () {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://hooks.example.com/receive', 'webhook_secret' => str_repeat('s', 32),
    ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'dns-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    app()->instance(WebhookUrlValidator::class, new class extends WebhookUrlValidator {
        protected function resolveRecords(string $host): array { return []; }
    });
    Http::fake();
    app(NotificationDispatcher::class)->deliver(NotificationDelivery::where('channel', 'webhook')->sole()->id);
    $delivery = NotificationDelivery::where('channel', 'webhook')->sole();
    expect($delivery->status)->toBe('pending')->and($delivery->next_attempt_at->isFuture())->toBeTrue();
    Http::assertNothingSent();
});

it('retries webhook timeouts and respects a rate limit retry-after value', function (int $status, ?string $retryAfter, int $delay) {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://hooks.example.com/receive', 'webhook_secret' => str_repeat('s', 32),
    ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'http-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $urls = Mockery::mock(WebhookUrlValidator::class);
    $urls->shouldReceive('validateResolvedAddress')->once()->andReturn('93.184.215.14');
    app()->instance(WebhookUrlValidator::class, $urls);
    Http::fake(['hooks.example.com/*' => Http::response([], $status, $retryAfter ? ['Retry-After' => $retryAfter] : [])]);
    $delivery = NotificationDelivery::where('channel', 'webhook')->sole();
    $before = now()->startOfSecond();
    app(NotificationDispatcher::class)->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('pending');
    expect($delivery->fresh()->next_attempt_at->equalTo($before->addSeconds($delay)))->toBeTrue();
})->with([
    [408, null, 30], [429, '600', 600], [503, '300', 300],
    [503, 'Thu, 01 Oct 2026 10:05:00 GMT', 300], [503, 'invalid', 30], [503, '9999999', 86400],
]);

it('permanently rejects private webhook DNS addresses without sending a request', function () {
    $org = managedNotificationOrganization('Tenant A');
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key, managedNotificationDefinitionInput($definition, [
        'webhook_url' => 'https://hooks.example.com/receive', 'webhook_secret' => str_repeat('s', 32),
    ]), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'private-dns-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    app()->instance(WebhookUrlValidator::class, new class extends WebhookUrlValidator {
        protected function resolveRecords(string $host): array { return [['ip' => '127.0.0.1']]; }
    });
    Http::fake();
    app(NotificationDispatcher::class)->deliver(NotificationDelivery::where('channel', 'webhook')->sole()->id);
    expect(NotificationDelivery::where('channel', 'webhook')->sole()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('rolls back partial dashboard resolution and retries without creating duplicates', function (bool $terminal) {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    config()->set('managed_notifications.max_attempts', 2);
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    app(NotificationDefinitionManager::class)->update($definition->type_key, managedNotificationDefinitionInput($definition), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'resolve-retry-review', managedNotificationValues());
    $dashboard = Mockery::mock(App\Services\ManagedNotifications\Channels\DashboardChannel::class);
    $dashboard->shouldReceive('write')->andReturnUsing(function ($event, $user, $message) {
        (new App\Services\ManagedNotifications\Channels\DashboardChannel())->write($event, $user, $message);
        throw new RuntimeException('Temporary dashboard transaction failure.');
    });
    app()->instance(App\Services\ManagedNotifications\Channels\DashboardChannel::class, $dashboard);
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe('pending')->and($event->fresh()->attempts)->toBe(1)
        ->and(NotificationLog::count())->toBe(0)->and(NotificationDelivery::count())->toBe(0);
    \Carbon\Carbon::setTestNow(now()->addSeconds(31));
    if (! $terminal) {
        app()->instance(App\Services\ManagedNotifications\Channels\DashboardChannel::class,
            new App\Services\ManagedNotifications\Channels\DashboardChannel());
    }
    app(NotificationResolver::class)->resolve($event->id);
    expect($event->fresh()->status)->toBe($terminal ? 'failed' : 'resolved')
        ->and(NotificationLog::count())->toBe($terminal ? 0 : 1)
        ->and(NotificationDelivery::count())->toBe($terminal ? 0 : 2);
    if ($terminal) {
        expect($event->fresh()->next_attempt_at)->toBeNull()->and($event->fresh()->attempts)->toBe(2);
    }
})->with([false, true]);

it('does not retry an accepted email after its acknowledgement write fails and recovers a webhook safely', function (string $channel) {
    \Carbon\Carbon::setTestNow('2026-10-01 10:00:00');
    $org = managedNotificationOrganization('Tenant A');
    $owner = managedNotificationUser('owner@example.com', 'user');
    managedNotificationOwner($org, $owner);
    $definition = NotificationDefinition::where('type_key', 'meta.template_status_changed')->firstOrFail();
    $input = $channel === 'email' ? ['delivery_mode' => 'email'] : [
        'delivery_mode' => 'dashboard', 'webhook_url' => 'https://hooks.example.com/receive',
        'webhook_secret' => str_repeat('s', 32),
    ];
    app(NotificationDefinitionManager::class)->update($definition->type_key, managedNotificationDefinitionInput($definition, $input), 1);
    $event = app(NotificationEventRecorder::class)->record($org, $definition->type_key, 'acknowledgement-review', managedNotificationValues());
    app(NotificationResolver::class)->resolve($event->id);
    $delivery = NotificationDelivery::where('channel', $channel)->sole();
    if ($channel === 'email') {
        Mail::shouldReceive('send')->once();
    } else {
        $urls = Mockery::mock(WebhookUrlValidator::class);
        $urls->shouldReceive('validateResolvedAddress')->twice()->andReturn('93.184.215.14');
        app()->instance(WebhookUrlValidator::class, $urls);
        Http::fake(['hooks.example.com/*' => Http::response([], 200)]);
    }
    $failOnce = true;
    DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$failOnce) {
        if ($failOnce && str_starts_with($sql, 'update "notification_deliveries"') && ($bindings[0] ?? null) === 'sent') {
            $failOnce = false;
            throw new RuntimeException('Acknowledgement storage unavailable.');
        }
    });
    $dispatcher = app(NotificationDispatcher::class);
    expect(fn () => $dispatcher->deliver($delivery->id))->toThrow(RuntimeException::class)
        ->and($delivery->fresh()->status)->toBe('processing');
    $dispatcher->deliver($delivery->id);
    \Carbon\Carbon::setTestNow(now()->addMinutes(6));
    Queue::fake();
    $this->artisan('notifications:reconcile')->assertSuccessful();
    expect($delivery->fresh()->status)->toBe($channel === 'email' ? 'uncertain' : 'pending');
    $dispatcher->deliver($delivery->id);
    if ($channel === 'webhook') {
        expect($delivery->fresh()->status)->toBe('sent');
        Http::assertSentCount(2);
        expect(Http::recorded()->map(fn ($pair) => $pair[0]->header('X-Chatberry-Idempotency-Key'))->unique()->count())->toBe(1);
    } else {
        expect($delivery->fresh()->status)->toBe('uncertain');
    }
})->with(['email', 'webhook']);
