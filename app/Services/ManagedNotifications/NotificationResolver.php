<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationDefinition;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Services\ManagedNotifications\Channels\DashboardChannel;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationResolver
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly NotificationRecipientResolver $recipients,
        private readonly DashboardChannel $dashboard,
    ) {
    }

    public function resolve(int $eventId): void
    {
        try {
            DB::transaction(function () use ($eventId) {
                $event = NotificationEvent::query()->lockForUpdate()->find($eventId);
                if (! $event || $event->status !== 'pending'
                    || ($event->next_attempt_at && $event->next_attempt_at->isFuture())) {
                    return;
                }

                $organization = Organization::query()->whereKey($event->organization_id)
                    ->whereNull('deleted_at')->first();
                $definition = NotificationDefinition::query()->where('type_key', $event->type_key)->first();
                if (! $organization || ! $definition || ! $definition->enabled) {
                    $event->update(['status' => 'canceled', 'resolved_at' => now()]);
                    return;
                }

                $allowed = $this->catalog->get($event->type_key)['variables'];
                $values = $event->variables;
                $this->renderer->validateValues($values, $allowed);
                $mode = $definition->delivery_mode;
                $rendered = [
                    'dashboard' => $this->renderer->render($definition->dashboard_template, $values, $allowed),
                    'subject' => $this->renderer->render($definition->email_subject, $values, $allowed),
                    'email' => $this->renderer->render($definition->email_body, $values, $allowed),
                    'sender' => $definition->sender_email,
                ];

                if (in_array($mode, ['email', 'dashboard_email'], true)
                    && (preg_match('/[\r\n]/', $rendered['subject']) || mb_strlen($rendered['subject']) > 191)) {
                    throw new \InvalidArgumentException('Rendered email subject is invalid.');
                }

                $recipientRows = $this->recipients->forOrganization($event->organization_id);
                $seenEmails = [];

                foreach ($recipientRows as $recipient) {
                    $userId = (int) $recipient->id;
                    if (in_array($mode, ['dashboard', 'dashboard_email'], true)) {
                        $key = hash('sha256', (string) $userId);
                        $log = $this->dashboard->write($event, $userId, $rendered['dashboard']);
                        NotificationDelivery::create([
                            'event_id' => $event->id,
                            'organization_id' => $event->organization_id,
                            'channel' => 'dashboard',
                            'recipient_key' => $key,
                            'recipient_user_id' => $userId,
                            'status' => 'sent',
                            'attempts' => 1,
                            'sent_at' => now(),
                            'notification_log_id' => $log->id,
                        ]);
                    }

                    $email = strtolower(trim((string) $recipient->email));
                    if (in_array($mode, ['email', 'dashboard_email'], true)
                        && filter_var($email, FILTER_VALIDATE_EMAIL)
                        && ! isset($seenEmails[$email])) {
                        $seenEmails[$email] = true;
                        NotificationDelivery::create([
                            'event_id' => $event->id,
                            'organization_id' => $event->organization_id,
                            'channel' => 'email',
                            'recipient_key' => hash('sha256', $email),
                            'recipient_user_id' => $userId,
                            'destination' => $email,
                            'status' => 'pending',
                        ]);
                    }
                }

                if ($definition->webhook_url) {
                    NotificationDelivery::create([
                        'event_id' => $event->id,
                        'channel' => 'webhook',
                        'recipient_key' => hash('sha256', 'configured-webhook'),
                        'organization_id' => $event->organization_id,
                        'destination' => $definition->webhook_url,
                        'webhook_secret' => $definition->webhook_secret,
                        'status' => 'pending',
                    ]);
                }

                $event->update([
                    'status' => 'resolved',
                    'definition_revision' => $definition->revision,
                    'rendered' => $rendered,
                    'resolved_at' => now(),
                    'last_error' => null,
                    'queued_at' => null,
                ]);
            });
        } catch (Throwable $exception) {
            NotificationEvent::query()->whereKey($eventId)->where('status', 'pending')
                ->increment('attempts');
            $event = NotificationEvent::query()->find($eventId);
            if ($event && $event->status === 'pending') {
                $event->update([
                    'status' => $event->attempts >= config('managed_notifications.max_attempts', 4) ? 'failed' : 'pending',
                    'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** max(0, $event->attempts - 1)))),
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'queued_at' => null,
                ]);
            }
            report($exception);
        }
    }
}
