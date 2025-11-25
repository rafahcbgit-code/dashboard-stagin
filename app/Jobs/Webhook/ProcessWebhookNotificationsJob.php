<?php

namespace App\Jobs\Webhook;

use App\Events\NewChatEvent;
use App\Models\Chat;
use App\Models\Contact;
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
 * This job broadcasts chat updates via Pusher and handles ticket assignment.
 * Separated from main processing to prevent notification failures from
 * blocking webhook processing.
 *
 * Dispatched by: ProcessWebhookJob after chat creation
 * Queue: notifications
 *
 * @see Story 1.4 for full implementation
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
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [5, 10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(int $chatId, int $contactId, int $organizationId, bool $isNewContact)
    {
        $this->chatId = $chatId;
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
        $this->isNewContact = $isNewContact;
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     *
     * Full implementation in Story 1.4:
     * - Broadcast NewChatEvent via Pusher
     * - Handle ticket assignment via ChatService
     * - Send push notifications if configured
     */
    public function handle(): void
    {
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

        // Broadcast NewChatEvent via Pusher
        try {
            event(new NewChatEvent($chat, $this->organizationId));

            Log::channel('webhook')->info('NewChatEvent broadcasted', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to broadcast NewChatEvent', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
            // Don't re-throw - broadcasting failure shouldn't fail the job
        }

        // Handle ticket assignment
        try {
            $chatService = new ChatService($this->organizationId);
            $chatService->handleTicketAssignment($this->contactId);

            Log::channel('webhook')->info('Ticket assignment handled', [
                'contact_id' => $this->contactId,
                'organization_id' => $this->organizationId,
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to handle ticket assignment', [
                'contact_id' => $this->contactId,
                'error' => $e->getMessage(),
            ]);
            // Don't re-throw - ticket assignment failure shouldn't fail the job
        }

        // TODO: Story 1.4 - Additional notification features
        // - Push notifications to mobile apps
        // - Email notifications based on org settings
        // - Slack/Teams integrations
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('ProcessWebhookNotificationsJob failed', [
            'chat_id' => $this->chatId,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
