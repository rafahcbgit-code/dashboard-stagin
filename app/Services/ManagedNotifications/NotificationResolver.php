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
        private readonly SubscriptionNotificationEligibility $subscriptionAlerts,
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

                $organizationExists = Organization::query()->whereKey($event->organization_id)
                    ->whereNull('deleted_at')->exists();
                $definition = NotificationDefinition::query()->where('type_key', $event->type_key)->sharedLock()->first();
                if (! $organizationExists || ! $definition || ! $definition->enabled || ! $this->subscriptionAlerts->isCurrent($event)) {
                    $event->update(['status' => 'canceled', 'resolved_at' => now(),
                        'queued_at' => null, 'last_error' => 'Notification disabled, organization unavailable, or subscription alert no longer current.']);

                    return;
                }

                $allowed = $this->catalog->get($event->type_key)['variables'];
                $values = $event->variables;
                $this->renderer->validateValues($values, $allowed);
                $mode = $event->type_key === 'subscription.resource_warning'
                    ? ((int) ($event->variables['threshold'] ?? 0) >= 90 ? 'dashboard_email' : 'dashboard')
                    : $definition->delivery_mode;
                $emailBody = $this->renderer->renderEmail($definition->email_body, $values, $allowed);
                $rendered = [
                    'dashboard' => $this->renderer->render($definition->dashboard_template, $values, $allowed),
                    'subject' => in_array($mode, ['email', 'dashboard_email'], true)
                        ? $this->renderer->renderEmailSubject($definition->email_subject, $values, $allowed)
                        : $this->renderer->render($definition->email_subject, $values, $allowed),
                    'email' => $emailBody['text'],
                    'email_html' => $emailBody['html'],
                    'sender' => $definition->sender_email,
                ];

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
            DB::transaction(function () use ($eventId, $exception): void {
                $event = NotificationEvent::query()->lockForUpdate()->find($eventId);
                if (! $event || $event->status !== 'pending') {
                    return;
                }
                $attempts = $event->attempts + 1;
                $failed = $attempts >= config('managed_notifications.max_attempts', 4);
                $event->update([
                    'attempts' => $attempts,
                    'status' => $failed ? 'failed' : 'pending',
                    'next_attempt_at' => $failed ? null : now()->addSeconds(min(900, 30 * (2 ** max(0, $attempts - 1)))),
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'queued_at' => null,
                ]);
            });
            report($exception);
        }
    }
}
