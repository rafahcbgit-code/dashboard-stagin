<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationDefinition;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Services\ManagedNotifications\Channels\EmailChannel;
use App\Services\ManagedNotifications\Channels\WebhookChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDispatcher
{
    public function __construct(
        private readonly EmailChannel $email,
        private readonly WebhookChannel $webhook,
        private readonly NotificationRecipientResolver $recipients,
        private readonly SubscriptionNotificationEligibility $subscriptionAlerts,
    ) {
    }

    public function deliver(int $deliveryId): void
    {
        $claimed = DB::transaction(function () use ($deliveryId) {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || $delivery->status !== 'pending'
                || ($delivery->next_attempt_at && $delivery->next_attempt_at->isFuture())) {
                return null;
            }

            $event = NotificationEvent::query()->find($delivery->event_id);
            $definition = $event
                ? NotificationDefinition::query()->where('type_key', $event->type_key)->sharedLock()->first()
                : null;
            $organizationExists = Organization::query()->whereKey($delivery->organization_id)
                ->whereNull('deleted_at')->exists();

            $eligible = $delivery->channel !== 'email' || $this->recipients->isEligibleEmail(
                $delivery->organization_id,
                (string) $delivery->destination,
                $delivery->recipient_user_id === null ? null : (int) $delivery->recipient_user_id
            );

            if (! $event || $event->status !== 'resolved' || (int) $event->organization_id !== (int) $delivery->organization_id
                || ! $definition || ! $definition->enabled || ! $organizationExists || ! $eligible) {
                $delivery->update(['status' => 'canceled', 'last_error' => 'Notification disabled or recipient no longer eligible.']);

                return null;
            }

            if (! $this->allowsCurrentChannel($definition, $delivery, $event) || ! $this->subscriptionAlerts->isCurrent($event)) {
                $delivery->update(['status' => 'canceled', 'queued_at' => null,
                    'last_error' => 'Delivery configuration changed or subscription alert no longer current.']);

                return null;
            }

            $delivery->update([
                'status' => 'processing',
                'attempts' => $delivery->attempts + 1,
                'claimed_at' => now(),
                'queued_at' => null,
            ]);

            return [$delivery, $event];
        });

        if (! $claimed) {
            return;
        }
        [$delivery, $event] = $claimed;

        try {
            match ($delivery->channel) {
                'email' => $this->email->send($delivery, $event),
                'webhook' => $this->webhook->send($delivery, $event),
                default => throw new PermanentDeliveryException('Unsupported delivery channel.'),
            };

        } catch (Throwable $exception) {
            $permanent = $exception instanceof PermanentDeliveryException;
            $maxAttempts = (int) config('managed_notifications.max_attempts', 4);
            $status = $permanent || $delivery->attempts >= $maxAttempts ? 'failed' : 'pending';
            $delay = min(900, 30 * (2 ** max(0, $delivery->attempts - 1)));
            if ($exception instanceof RetryableDeliveryException) {
                $delay = max($delay, $exception->retryAfterSeconds);
            }
            NotificationDelivery::query()->whereKey($deliveryId)->where('status', 'processing')->update([
                'status' => $status,
                'next_attempt_at' => $status === 'pending'
                    ? now()->addSeconds($delay)
                    : null,
                'claimed_at' => null,
                'last_error' => $this->safeError($exception),
                'updated_at' => now(),
            ]);
            Log::warning('Notification delivery failed', [
                'delivery_id' => $deliveryId,
                'exception' => get_class($exception),
                'error' => $this->safeError($exception),
            ]);

            return;
        }

        // A failed acknowledgement write must not turn an accepted email into an automatic retry.
        // Leave its claim for reconciliation: email becomes uncertain; webhook keeps its stable key.
        NotificationDelivery::query()->whereKey($deliveryId)->where('status', 'processing')->update([
            'status' => 'sent',
            'sent_at' => now(),
            'claimed_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function allowsCurrentChannel(NotificationDefinition $definition, NotificationDelivery $delivery, NotificationEvent $event): bool
    {
        return match ($delivery->channel) {
            'email' => $event->type_key === 'subscription.resource_warning'
                ? (int) ($event->variables['threshold'] ?? 0) >= 90
                : in_array($definition->delivery_mode, ['email', 'dashboard_email'], true),
            'webhook' => $definition->webhook_url !== null
                && $definition->webhook_url === $delivery->destination
                && hash_equals((string) $definition->webhook_secret, (string) $delivery->webhook_secret),
            default => true,
        };
    }

    private function safeError(Throwable $exception): string
    {
        $message = preg_replace('~https?://\S+~i', '[url]', $exception->getMessage());
        $message = preg_replace('~\b(Bearer|Basic)\s+\S+~i', '[credential]', $message);

        return mb_substr($message, 0, 1000);
    }
}
