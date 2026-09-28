<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationDefinition;
use App\Models\NotificationDefinition;
use App\Services\ManagedNotifications\Channels\EmailChannel;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Services\ManagedNotifications\NotificationDefinitionManager;
use App\Services\ManagedNotifications\NotificationTemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

class ManagedNotificationController extends Controller
{
    public function index(NotificationCatalog $catalog, NotificationTemplateRenderer $renderer)
    {
        $catalog->ensureDefinitions();
        $definitions = NotificationDefinition::query()
            ->whereIn('type_key', array_keys($catalog->all()))
            ->orderBy('type_key')->get()
            ->map(fn ($definition) => [
                'type_key' => $definition->type_key,
                'name' => $catalog->get($definition->type_key)['name'],
                'description' => $catalog->get($definition->type_key)['description'] ?? null,
                'variables' => $catalog->get($definition->type_key)['variables'],
                'enabled' => $definition->enabled,
                'delivery_mode' => $definition->delivery_mode,
                'dashboard_template' => $definition->dashboard_template,
                'email_subject' => $definition->email_subject,
                'email_body' => $definition->email_body,
                'sender_email' => $definition->sender_email,
                'webhook_url' => $definition->webhook_url,
                'has_webhook_secret' => (bool) $definition->webhook_secret,
                'revision' => $definition->revision,
                'test_preview' => $this->testPreviewForDisplay($definition, $catalog, $renderer),
            ]);

        return Inertia::render('Admin/Setting/ManagedNotifications', [
            'definitions' => $definitions,
            'senders' => $catalog->approvedSenders(),
        ]);
    }

    public function deliveries()
    {
        $deliveries = DB::table('notification_deliveries')
            ->select('id', 'event_id', 'organization_id', 'channel', 'status',
                'attempts', 'last_error', 'created_at', 'sent_at')
            ->orderByDesc('id')->cursorPaginate(25);
        $eventIds = $deliveries->getCollection()->pluck('event_id')->unique();
        $events = $eventIds->isEmpty() ? collect() : DB::table('notification_events')
            ->whereIn('id', $eventIds)->get(['id', 'type_key', 'is_test'])->keyBy('id');
        $deliveries->getCollection()->transform(function ($delivery) use ($events) {
            $event = $events->get($delivery->event_id);

            return [
                'id' => $delivery->id,
                'organization_id' => $delivery->organization_id,
                'type_key' => $event?->type_key,
                'is_test' => $event?->is_test,
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->last_error,
                'created_at' => $delivery->created_at,
                'sent_at' => $delivery->sent_at,
            ];
        });

        return Inertia::render('Admin/Setting/NotificationDeliveries', [
            'deliveries' => $deliveries,
        ]);
    }

    public function update(
        UpdateNotificationDefinition $request,
        string $typeKey,
        NotificationCatalog $catalog,
        NotificationDefinitionManager $manager
    ) {
        abort_unless(array_key_exists($typeKey, $catalog->all()), 404);
        try {
            $manager->update($typeKey, $request->validated(), (int) $request->user('admin')->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['definition' => $exception->getMessage()]);
        }

        return back()->with('status', ['type' => 'success', 'message' => 'Notification settings saved.']);
    }

    public function testEmail(
        Request $request,
        string $typeKey,
        NotificationCatalog $catalog,
        NotificationTemplateRenderer $renderer,
        EmailChannel $email
    ) {
        abort_unless(array_key_exists($typeKey, $catalog->all()), 404);
        $request->merge([
            'sender_email' => strtolower(trim((string) $request->input('sender_email'))),
            'recipient_email' => trim((string) $request->input('recipient_email')),
        ]);
        $data = $request->validate([
            'sender_email' => ['required', 'email', 'max:191', Rule::in($catalog->approvedSenders())],
            'recipient_email' => ['required', 'email', 'max:191'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        $catalog->ensureDefinitions();
        $definition = NotificationDefinition::query()->where('type_key', $typeKey)->firstOrFail();
        if ((int) $data['revision'] !== $definition->revision) {
            throw ValidationException::withMessages(['test_email' => 'Notification template changed; reload the preview before sending.']);
        }

        try {
            $preview = $this->testPreview($definition, $catalog, $renderer);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['test_email' => $exception->getMessage()]);
        }

        try {
            $email->sendMessage($data['sender_email'], $data['recipient_email'],
                $preview['subject'], $preview['body'], $preview['html']);
        } catch (Throwable $exception) {
            Log::warning('Notification test email failed', [
                'type_key' => $typeKey,
                'admin_id' => $request->user('admin')->id,
                'exception' => get_class($exception),
            ]);

            return back()->with('status', [
                'type' => 'error',
                'message' => 'Test email failed at the configured mail transport. Check mail settings.',
            ]);
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => 'Test email accepted by the configured mail transport for '.$data['recipient_email'].'.',
        ]);
    }

    private function testPreviewForDisplay(
        NotificationDefinition $definition,
        NotificationCatalog $catalog,
        NotificationTemplateRenderer $renderer
    ): array {
        try {
            return $this->testPreview($definition, $catalog, $renderer);
        } catch (InvalidArgumentException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    private function testPreview(
        NotificationDefinition $definition,
        NotificationCatalog $catalog,
        NotificationTemplateRenderer $renderer
    ): array {
        $type = $catalog->get($definition->type_key);
        $values = $catalog->sampleValues($definition->type_key);
        $renderer->validateValues($values, $type['variables']);
        $subject = '[TEST] '.$renderer->renderEmailSubject($definition->email_subject, $values, $type['variables']);
        if (preg_match('/[\r\n]/', $subject) || mb_strlen($subject) > 191) {
            throw new InvalidArgumentException('Rendered test subject is invalid.');
        }

        $body = $renderer->renderEmail($definition->email_body, $values, $type['variables']);

        return [
            'variables' => $values,
            'subject' => $subject,
            'body' => "This is a Chatberry notification test.\n\n".$body['text'],
            'html' => '<p>This is a Chatberry notification test.</p>'.$body['html'],
        ];
    }
}
