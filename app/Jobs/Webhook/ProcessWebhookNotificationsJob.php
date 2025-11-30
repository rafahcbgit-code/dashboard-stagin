<?php

namespace App\Jobs\Webhook;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\ApiNotificationService;
use App\Services\ChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessWebhookNotificationsJob - Handles real-time notifications for webhooks.
 *
 * This job broadcasts chat updates via Pusher, sends FCM push notifications,
 * triggers external webhooks, and handles ticket assignment. Implements
 * fire-and-forget pattern - all notification failures are logged but don't
 * fail the job.
 *
 * Dispatched by: ProcessWebhookJob after chat creation
 * Queue: webhook-high
 *
 * AC-1.4.1: Pusher broadcast sends NewChatEvent
 * AC-1.4.2: FCM push notification to mobile devices
 * AC-1.4.3: External webhooks via WebhookHelper
 * AC-1.4.4: Fire-and-forget pattern (failures logged, not thrown)
 * AC-1.4.5: Performance logging with 3s warning threshold
 *
 * @see Story 1.4: Create ProcessWebhookNotificationsJob for Async Broadcasts
 */
class ProcessWebhookNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The chat ID to broadcast.
     */
    public int $chatId;

    /**
     * The contact ID for ticket assignment.
     */
    public int $contactId;

    /**
     * The organization ID for scoping.
     */
    public int $organizationId;

    /**
     * Whether this is a new contact.
     */
    public bool $isNewContact;

    /**
     * The number of times the job may be attempted.
     * Set to 1 for fire-and-forget pattern (no retries).
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Performance warning threshold in seconds.
     */
    protected const PERFORMANCE_WARNING_THRESHOLD = 3.0;

    /**
     * Create a new job instance.
     */
    public function __construct(int $chatId, int $contactId, int $organizationId, bool $isNewContact)
    {
        $this->chatId = $chatId;
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
        $this->isNewContact = $isNewContact;
        $this->onQueue('webhook-high');
    }

    /**
     * Execute the job.
     *
     * Orchestrates all notification types with fire-and-forget pattern:
     * 1. Broadcast NewChatEvent via Pusher (AC-1.4.1)
     * 2. Send FCM push notification (AC-1.4.2)
     * 3. Trigger external webhooks (AC-1.4.3)
     * 4. Handle ticket assignment
     *
     * All failures are logged but don't fail the job (AC-1.4.4).
     * Performance is logged with 3s warning threshold (AC-1.4.5).
     */
    public function handle(): void
    {
        $jobStartTime = microtime(true);
        $durations = [];

        Log::channel('webhook')->info('ProcessWebhookNotificationsJob started', [
            'chat_id' => $this->chatId,
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
            'is_new_contact' => $this->isNewContact,
        ]);

        // Load chat with relationships for broadcasting
        $chat = Chat::with(['contact', 'media'])
            ->find($this->chatId);

        if (! $chat) {
            Log::channel('webhook')->warning('Chat not found for notification', [
                'chat_id' => $this->chatId,
            ]);

            return;
        }

        // 1. AC-1.4.1: Broadcast NewChatEvent via Pusher
        $durations['pusher'] = $this->sendPusherBroadcast($chat);

        // 2. AC-1.4.2: Send FCM push notification
        $durations['fcm'] = $this->sendFcmNotification($chat);

        // 3. AC-1.4.3: Trigger external webhooks
        $durations['webhook'] = $this->triggerExternalWebhook($chat);

        // 4. Handle ticket assignment (existing functionality)
        $durations['ticket'] = $this->handleTicketAssignment();

        // AC-1.4.5: Log total performance
        $this->logPerformance($jobStartTime, $durations);
    }

    /**
     * Send Pusher broadcast via NewChatEvent.
     *
     * AC-1.4.1: Pusher broadcast sends NewChatEvent for real-time chat updates.
     *
     * @param  Chat  $chat The chat to broadcast
     * @return float Duration in milliseconds
     */
    protected function sendPusherBroadcast(Chat $chat): float
    {
        $startTime = microtime(true);

        try {
            event(new NewChatEvent($chat, $this->organizationId, $chat->contact_id));

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->info('Pusher broadcast sent', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'duration_ms' => round($duration, 2),
            ]);

            return $duration;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->warning('Pusher broadcast failed', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
            ]);

            // Fire-and-forget: don't re-throw
            return $duration;
        }
    }

    /**
     * Send FCM push notification via ApiNotificationService.
     *
     * AC-1.4.2: FCM push notification is sent to mobile devices with valid device tokens.
     *
     * @param  Chat  $chat The chat to notify about
     * @return float Duration in milliseconds
     */
    protected function sendFcmNotification(Chat $chat): float
    {
        $startTime = microtime(true);

        try {
            $apiNotificationService = new ApiNotificationService();
            $apiNotificationService->sendNewMessageNotification($chat, $this->organizationId);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->info('FCM notification sent', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'duration_ms' => round($duration, 2),
            ]);

            return $duration;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->warning('FCM notification failed', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
            ]);

            // Fire-and-forget: don't re-throw
            return $duration;
        }
    }

    /**
     * Trigger external webhook via WebhookHelper.
     *
     * AC-1.4.3: External webhooks fire via WebhookHelper::triggerWebhookEvent().
     *
     * @param  Chat  $chat The chat to trigger webhook for
     * @return float Duration in milliseconds
     */
    protected function triggerExternalWebhook(Chat $chat): float
    {
        $startTime = microtime(true);

        try {
            $metadata = json_decode($chat->metadata, true) ?? [];

            $webhookData = [
                'event' => 'message.received',
                'chat_id' => $chat->uuid,
                'contact_id' => $chat->contact->uuid ?? null,
                'contact_phone' => $chat->contact->phone ?? null,
                'message_type' => $metadata['type'] ?? 'text',
                'is_new_contact' => $this->isNewContact,
                'timestamp' => now()->toIso8601String(),
            ];

            WebhookHelper::triggerWebhookEvent('message.received', $webhookData, $this->organizationId);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->info('External webhook triggered', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'event' => 'message.received',
                'duration_ms' => round($duration, 2),
            ]);

            return $duration;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->warning('External webhook failed', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
            ]);

            // Fire-and-forget: don't re-throw
            return $duration;
        }
    }

    /**
     * Handle ticket assignment via ChatService.
     *
     * @return float Duration in milliseconds
     */
    protected function handleTicketAssignment(): float
    {
        $startTime = microtime(true);

        try {
            $chatService = new ChatService($this->organizationId);
            $chatService->handleTicketAssignment($this->contactId);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->info('Ticket assignment handled', [
                'contact_id' => $this->contactId,
                'organization_id' => $this->organizationId,
                'duration_ms' => round($duration, 2),
            ]);

            return $duration;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->warning('Ticket assignment failed', [
                'contact_id' => $this->contactId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
            ]);

            // Fire-and-forget: don't re-throw
            return $duration;
        }
    }

    /**
     * Log overall performance metrics.
     *
     * AC-1.4.5: Notifications complete within 3 seconds total (warning if exceeded).
     *
     * @param  float  $jobStartTime Job start time from microtime(true)
     * @param  array  $durations Individual operation durations in ms
     */
    protected function logPerformance(float $jobStartTime, array $durations): void
    {
        $totalDurationMs = (microtime(true) - $jobStartTime) * 1000;
        $totalDurationSeconds = $totalDurationMs / 1000;

        $logData = [
            'chat_id' => $this->chatId,
            'organization_id' => $this->organizationId,
            'total_duration_ms' => round($totalDurationMs, 2),
            'pusher_duration_ms' => round($durations['pusher'] ?? 0, 2),
            'fcm_duration_ms' => round($durations['fcm'] ?? 0, 2),
            'webhook_duration_ms' => round($durations['webhook'] ?? 0, 2),
            'ticket_duration_ms' => round($durations['ticket'] ?? 0, 2),
        ];

        if ($totalDurationSeconds > self::PERFORMANCE_WARNING_THRESHOLD) {
            Log::channel('webhook')->warning('ProcessWebhookNotificationsJob exceeded 3s threshold', $logData);
        } else {
            Log::channel('webhook')->info('ProcessWebhookNotificationsJob completed', $logData);
        }
    }

    /**
     * Handle a job failure.
     *
     * Note: With $tries = 1, this will only be called on unexpected failures
     * (e.g., job timeout, queue issues). Individual notification failures
     * are handled gracefully and won't trigger this method.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('ProcessWebhookNotificationsJob failed unexpectedly', [
            'chat_id' => $this->chatId,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
