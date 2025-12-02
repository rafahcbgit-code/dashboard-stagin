<?php

namespace App\Jobs\Webhook;

use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatStatusLog;
use App\Models\MissedChatStatusUpdate;
use App\Models\Organization;
use App\Models\WebhookFailure;
use App\Services\AutoReplyService;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\SubscriptionService;
use App\Services\UnsubscribeChecker;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessWebhookJob - Async webhook processing job.
 *
 * This job handles all webhook processing in the background, allowing the
 * WebhookController to return HTTP 200 immediately (Immediate 200 OK Pattern).
 *
 * Architecture Pattern: Job Orchestration
 * - Coordinates contact creation, chat storage, and child job dispatch
 * - Uses idempotency check via wam_id to prevent duplicate processing
 * - Maintains FIFO ordering per contact using WithoutOverlapping middleware
 *
 * @see docs/architecture-performance-optimization.md#Webhook-Processing-Architecture
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The webhook payload data.
     */
    public array $payload;

    /**
     * The organization ID for scoping.
     */
    public int $organizationId;

    /**
     * The type of webhook being processed.
     * Possible values: messages, smb_message_echoes, smb_app_state_sync,
     * history, message_template_status_update, account_review_update,
     * phone_number_name_update, phone_number_quality_update, business_capability_update
     */
    public string $webhookType;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of seconds to wait before retrying the job.
     * Exponential backoff: 1 min, 5 min, 15 min
     */
    public array $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload, int $organizationId, string $webhookType)
    {
        $this->payload = $payload;
        $this->organizationId = $organizationId;
        $this->webhookType = $webhookType;
        $this->onQueue('webhook-high');
    }

    /**
     * Get the middleware the job should pass through.
     *
     * FR19: Implements FIFO ordering per contact using WithoutOverlapping.
     * Jobs for the same contact will be processed sequentially.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Extract contact phone from payload for FIFO ordering
        $contactPhone = $this->extractContactPhone();

        if ($contactPhone) {
            // Use contact phone as the lock key for FIFO ordering per contact
            return [
                (new WithoutOverlapping("webhook-contact-{$this->organizationId}-{$contactPhone}"))
                    ->releaseAfter(60) // Release lock after 60 seconds if job takes too long
                    ->expireAfter(180), // Lock expires after 3 minutes
            ];
        }

        return [];
    }

    /**
     * Execute the job.
     *
     * Routes to appropriate processor based on webhook type.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        Log::channel('webhook')->info('ProcessWebhookJob started', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'job_id' => $this->job?->getJobId(),
        ]);

        try {
            match ($this->webhookType) {
                'messages' => $this->processMessages(),
                'statuses' => $this->processStatuses(),
                'smb_message_echoes' => $this->processSmbMessageEchoes(),
                'smb_app_state_sync' => $this->processSmbAppStateSync(),
                'history' => $this->processHistory(),
                'message_template_status_update' => $this->processTemplateStatusUpdate(),
                'account_review_update',
                'phone_number_name_update',
                'phone_number_quality_update',
                'business_capability_update' => $this->processAccountUpdate(),
                default => $this->logUnknownWebhookType(),
            };

            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::channel('webhook')->info('ProcessWebhookJob completed', [
                'organization_id' => $this->organizationId,
                'webhook_type' => $this->webhookType,
                'processing_time_ms' => round($processingTime, 2),
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->error('ProcessWebhookJob error', [
                'organization_id' => $this->organizationId,
                'webhook_type' => $this->webhookType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Process incoming messages from webhook payload.
     *
     * Handles: text, image, video, document, audio, location, contacts, interactive
     */
    protected function processMessages(): void
    {
        // AC-1.8.1: Check subscription limit before processing inbound messages
        // AC-1.8.2: If limit reached, log warning and skip processing
        // AC-1.8.3: Job completes successfully (no exception) to maintain 200 OK pattern
        if (SubscriptionService::isSubscriptionLimitReachedForInboundMessages($this->organizationId)) {
            Log::channel('webhook')->warning('Subscription limit reached - skipping message processing', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $value = $this->payload['value'] ?? [];
        $messages = $value['messages'] ?? [];
        $contacts = $value['contacts'] ?? [];
        $statuses = $value['statuses'] ?? [];

        // Process status updates if present in messages webhook
        if (! empty($statuses)) {
            foreach ($statuses as $status) {
                $this->processStatus($status);
            }
        }

        // Process each message
        foreach ($messages as $message) {
            $contactInfo = $contacts[0] ?? null;
            $this->processMessage($message, $contactInfo);
        }
    }

    /**
     * Process a single message.
     *
     * AC-1.2.1: Creates contact via ContactService
     * AC-1.2.2: Creates chat via Chat model
     * AC-1.2.3: Dispatches DownloadWebhookMediaJob for media
     * AC-1.2.4: Dispatches ProcessWebhookNotificationsJob
     * AC-1.2.5: Idempotency check via wam_id
     *
     * @param  array  $message The message data from webhook
     * @param  array|null  $contactInfo Contact info from webhook
     */
    protected function processMessage(array $message, ?array $contactInfo): void
    {
        $wamId = $message['id'] ?? null;
        $senderPhone = $message['from'] ?? null;
        $messageType = $message['type'] ?? 'text';
        $timestamp = $message['timestamp'] ?? time();

        if (! $wamId || ! $senderPhone) {
            Log::channel('webhook')->warning('Invalid message data - missing wam_id or from', [
                'organization_id' => $this->organizationId,
                'message' => $message,
            ]);

            return;
        }

        // AC-1.2.5: Idempotency check - prevent duplicate chat creation
        // Uses idx_wam_org index for efficient lookup
        if ($this->isDuplicateMessage($wamId)) {
            Log::channel('webhook')->info('Duplicate webhook skipped', [
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
            ]);

            return;
        }

        // AC-1.2.1: Find or create contact via ContactService
        $contactService = new ContactService($this->organizationId);
        $contactName = $contactInfo['profile']['name'] ?? null;
        $result = $contactService->findOrCreateFromWebhook($senderPhone, $contactName);
        $contact = $result['contact'];
        $isNewContact = $result['isNew'];

        // AC-1.9.1, AC-1.9.2: Check for unsubscribe keywords in text and button messages
        // AC-1.9.4: Wrapped in try-catch to isolate failures - job continues on error
        try {
            // Extract message text from text.body or button.text
            $messageText = $message['text']['body'] ?? null;
            $buttonText = $message['button']['text'] ?? null;

            $textToCheck = $messageText ?? $buttonText;

            if ($textToCheck) {
                $organization = Organization::find($this->organizationId);
                if ($organization) {
                    $unsubscribeChecker = new UnsubscribeChecker();
                    $unsubscribeChecker->check($organization, $textToCheck, $contact->id);
                }
            }
        } catch (\Throwable $e) {
            // AC-1.9.4: Log warning but don't fail the job
            Log::channel('webhook')->warning('UnsubscribeChecker failed (non-blocking)', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Build metadata for the chat
        $metadata = $this->buildMessageMetadata($message, $messageType);

        // AC-1.2.2: Create chat record
        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'inbound',
            'metadata' => json_encode($metadata),
            'status' => 'received',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        // Create ChatLog entry for the chat thread view
        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        // AC-1.7.4: Process any pending MissedChatStatusUpdates for this wam_id
        $this->processPendingStatusUpdates($chat, $contact);

        Log::channel('webhook')->info('Chat created', [
            'organization_id' => $this->organizationId,
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'message_type' => $messageType,
        ]);

        // AC-1.2.3: Dispatch DownloadWebhookMediaJob for media messages
        if ($this->hasMedia($message, $messageType)) {
            $mediaData = $this->extractMediaData($message, $messageType);
            DownloadWebhookMediaJob::dispatch(
                $chat->id,
                $contact->id,
                $this->organizationId,
                $mediaData
            )->onQueue('webhook-high');

            Log::channel('webhook')->info('DownloadWebhookMediaJob dispatched', [
                'chat_id' => $chat->id,
                'media_type' => $messageType,
            ]);
        }

        // AC-1.2.4: Dispatch ProcessWebhookNotificationsJob for real-time updates
        // Uses webhook-high queue for priority processing (fire-and-forget pattern)
        // Pass raw WhatsApp payload for backward-compatible external webhooks
        ProcessWebhookNotificationsJob::dispatch(
            $chat->id,
            $contact->id,
            $this->organizationId,
            $isNewContact,
            $this->payload
        )->onQueue('webhook-high');

        Log::channel('webhook')->info('ProcessWebhookNotificationsJob dispatched', [
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
        ]);

        // Handle ticket assignment (moved to notification job to avoid blocking)
        // The ChatService::handleTicketAssignment will be called by notification job

        // AC-1.8.4: Check message limit before auto-reply
        // AC-1.8.5: Chat is already stored above; only auto-reply is conditional
        $isMessageLimitReached = SubscriptionService::isSubscriptionFeatureLimitReached(
            $this->organizationId,
            'message_limit'
        );

        // Auto-reply integration - check triggers after chat creation
        // This is fire-and-forget; failures don't fail the main job
        // Only process auto-reply for supported message types (text, button, audio, interactive)
        $autoReplyTypes = ['text', 'button', 'audio', 'interactive'];

        if ($isMessageLimitReached) {
            Log::channel('webhook')->info('Auto-reply skipped - message limit reached', [
                'organization_id' => $this->organizationId,
                'chat_id' => $chat->id,
                'contact_id' => $contact->id,
            ]);
        } elseif (! $contact->bot_enable) {
            Log::channel('webhook')->info('Auto-reply skipped - bot disabled for contact', [
                'chat_id' => $chat->id,
                'contact_id' => $contact->id,
            ]);
        } elseif (! in_array($messageType, $autoReplyTypes)) {
            Log::channel('webhook')->debug('Auto-reply skipped - unsupported message type', [
                'chat_id' => $chat->id,
                'message_type' => $messageType,
            ]);
        } else {
            try {
                $autoReplyService = new AutoReplyService();
                $autoReplyService->checkAutoReply($chat, $isNewContact);
            } catch (\Throwable $e) {
                Log::channel('webhook')->warning('Auto-reply check failed (non-blocking)', [
                    'chat_id' => $chat->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't re-throw - auto-reply failure shouldn't fail the job
            }
        }
    }

    /**
     * Check if a message with this wam_id already exists (idempotency).
     *
     * AC-1.2.5: Utilizes idx_wam_org index for efficient lookup.
     *
     * @param  string  $wamId The WhatsApp message ID
     * @return bool True if duplicate exists
     */
    protected function isDuplicateMessage(string $wamId): bool
    {
        return Chat::where('organization_id', $this->organizationId)
            ->where('wam_id', $wamId)
            ->exists();
    }

    /**
     * Build metadata array for the chat record.
     *
     * Handles all message types: text, image, video, document, audio,
     * location, contacts, interactive, button, sticker, reaction
     *
     * @param  array  $message The message data
     * @param  string  $type The message type
     * @return array The metadata array
     */
    protected function buildMessageMetadata(array $message, string $type): array
    {
        $metadata = [
            'type' => $type,
            'timestamp' => $message['timestamp'] ?? time(),
        ];

        // Add type-specific data
        switch ($type) {
            case 'text':
                $metadata['text'] = $message['text'] ?? ['body' => ''];
                break;

            case 'image':
                $metadata['image'] = $message['image'] ?? [];
                break;

            case 'video':
                $metadata['video'] = $message['video'] ?? [];
                break;

            case 'document':
                $metadata['document'] = $message['document'] ?? [];
                break;

            case 'audio':
                $metadata['audio'] = $message['audio'] ?? [];
                break;

            case 'sticker':
                $metadata['sticker'] = $message['sticker'] ?? [];
                break;

            case 'location':
                $metadata['location'] = $message['location'] ?? [];
                break;

            case 'contacts':
                $metadata['contacts'] = $message['contacts'] ?? [];
                break;

            case 'interactive':
                $metadata['interactive'] = $message['interactive'] ?? [];
                break;

            case 'button':
                $metadata['button'] = $message['button'] ?? [];
                break;

            case 'reaction':
                $metadata['reaction'] = $message['reaction'] ?? [];
                break;

            case 'order':
                $metadata['order'] = $message['order'] ?? [];
                break;

            case 'system':
                $metadata['system'] = $message['system'] ?? [];
                break;

            default:
                // Store the entire message for unknown types
                $metadata['raw'] = $message;
        }

        // Include context if present (for replies)
        if (isset($message['context'])) {
            $metadata['context'] = $message['context'];
        }

        return $metadata;
    }

    /**
     * Check if the message contains media that needs to be downloaded.
     *
     * @param  array  $message The message data
     * @param  string  $type The message type
     * @return bool True if message has downloadable media
     */
    protected function hasMedia(array $message, string $type): bool
    {
        $mediaTypes = ['image', 'video', 'document', 'audio', 'sticker'];

        if (! in_array($type, $mediaTypes)) {
            return false;
        }

        // Check if media ID exists
        return isset($message[$type]['id']);
    }

    /**
     * Extract media data for download job.
     *
     * @param  array  $message The message data
     * @param  string  $type The message type
     * @return array Media data for download job
     */
    protected function extractMediaData(array $message, string $type): array
    {
        $mediaData = $message[$type] ?? [];

        return [
            'type' => $type,
            'media_id' => $mediaData['id'] ?? null,
            'mime_type' => $mediaData['mime_type'] ?? null,
            'sha256' => $mediaData['sha256'] ?? null,
            'filename' => $mediaData['filename'] ?? null, // For documents
            'caption' => $mediaData['caption'] ?? null,
        ];
    }

    /**
     * Extract contact phone from payload for FIFO ordering.
     *
     * @return string|null The contact phone number
     */
    protected function extractContactPhone(): ?string
    {
        $value = $this->payload['value'] ?? [];

        // Try to get from messages
        $messages = $value['messages'] ?? [];
        if (! empty($messages)) {
            return $messages[0]['from'] ?? null;
        }

        // Try to get from statuses
        $statuses = $value['statuses'] ?? [];
        if (! empty($statuses)) {
            return $statuses[0]['recipient_id'] ?? null;
        }

        // Try to get from SMB message echoes (contact is recipient, not sender)
        $messageEchoes = $value['message_echoes'] ?? [];
        if (! empty($messageEchoes)) {
            return $messageEchoes[0]['to'] ?? null;
        }

        return null;
    }

    /**
     * Process status update webhooks.
     */
    protected function processStatuses(): void
    {
        $value = $this->payload['value'] ?? [];
        $statuses = $value['statuses'] ?? [];

        foreach ($statuses as $status) {
            $this->processStatus($status);
        }
    }

    /**
     * Process a single status update.
     *
     * @param  array  $status The status data from webhook
     */
    protected function processStatus(array $status): void
    {
        $wamId = $status['id'] ?? null;
        $statusType = $status['status'] ?? null; // sent, delivered, read, failed
        $timestamp = $status['timestamp'] ?? time();

        if (! $wamId || ! $statusType) {
            return;
        }

        // Find the chat by wam_id
        $chat = Chat::where('organization_id', $this->organizationId)
            ->where('wam_id', $wamId)
            ->first();

        if (! $chat) {
            // AC-1.7.1: Status update for unknown chat - store for later processing
            // AC-1.7.3: Check for duplicate before creating
            $exists = MissedChatStatusUpdate::where('organization_id', $this->organizationId)
                ->where('wam_id', $wamId)
                ->where('status', $statusType)
                ->exists();

            if (! $exists) {
                // AC-1.7.2: Create MissedChatStatusUpdate record
                MissedChatStatusUpdate::create([
                    'wam_id' => $wamId,
                    'organization_id' => $this->organizationId,
                    'payload' => json_encode($status),
                    'status' => $statusType,
                    'state' => MissedChatStatusUpdate::STATE_NOT_PROCESSED,
                    'created_at' => now(),
                ]);

                Log::channel('webhook')->info('MissedChatStatusUpdate created', [
                    'organization_id' => $this->organizationId,
                    'wam_id' => $wamId,
                    'status' => $statusType,
                ]);
            } else {
                Log::channel('webhook')->debug('Duplicate MissedChatStatusUpdate skipped', [
                    'organization_id' => $this->organizationId,
                    'wam_id' => $wamId,
                    'status' => $statusType,
                ]);
            }

            return;
        }

        // Update chat status
        $chat->update(['status' => $statusType]);

        // Create status log entry
        // Note: chat_status_logs table only has: id, chat_id, metadata, created_at
        // Store status and timestamp in metadata JSON
        ChatStatusLog::create([
            'chat_id' => $chat->id,
            'metadata' => json_encode(array_merge($status, [
                'status' => $statusType,
                'timestamp' => $timestamp,
            ])),
            'created_at' => now(),
        ]);

        Log::channel('webhook')->info('Chat status updated', [
            'chat_id' => $chat->id,
            'wam_id' => $wamId,
            'status' => $statusType,
        ]);

        // AC-1.7.6: Trigger external webhook for status update (fire-and-forget)
        try {
            WebhookHelper::triggerWebhookEvent('message.status.update', [
                'data' => [
                    'status' => $statusType,
                    'wam_id' => $wamId,
                    'chat_id' => $chat->id,
                    'contact_id' => $chat->contact_id,
                    'timestamp' => $timestamp,
                ],
            ], $this->organizationId);
        } catch (\Throwable $e) {
            // Fire-and-forget - don't fail on webhook error
            Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                'event' => 'message.status.update',
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process SMB message echoes (messages sent via WhatsApp Business App).
     *
     * SMB (Small/Medium Business) Message Echoes are webhooks sent by Meta when
     * messages are sent from the WhatsApp Business App. This enables "coexistence mode"
     * where organizations can use both the Business App and Chatberry simultaneously.
     *
     * AC-1.10.1: Creates/finds contact by recipient phone (to field)
     * AC-1.10.2: Chat record created with type='outbound' for echoed messages
     * AC-1.10.3: Media messages dispatch DownloadWebhookMediaJob
     * AC-1.10.4: ChatLog entry created for chat thread view
     * AC-1.10.5: NewChatEvent broadcast for real-time UI updates (via ProcessWebhookNotificationsJob)
     * AC-1.10.6: Duplicate echoes (same wam_id) are skipped
     * AC-1.10.7: External webhook 'smb.message.echo' triggered
     */
    protected function processSmbMessageEchoes(): void
    {
        $value = $this->payload['value'] ?? [];
        $messageEchoes = $value['message_echoes'] ?? [];

        if (empty($messageEchoes)) {
            Log::channel('webhook')->debug('SMB message echoes - empty payload', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        Log::channel('webhook')->info('Processing SMB message echoes', [
            'organization_id' => $this->organizationId,
            'echo_count' => count($messageEchoes),
        ]);

        foreach ($messageEchoes as $echo) {
            $this->processSmbMessageEcho($echo);
        }

        // AC-1.10.7: Trigger external webhook for SMB message echo (fire-and-forget)
        try {
            WebhookHelper::triggerWebhookEvent('smb.message.echo', [
                'data' => [
                    'echo_count' => count($messageEchoes),
                    'payload' => $this->payload,
                ],
            ], $this->organizationId);
        } catch (\Throwable $e) {
            // Fire-and-forget - don't fail on webhook error
            Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                'event' => 'smb.message.echo',
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a single SMB message echo.
     *
     * Key differences from inbound messages:
     * - Contact lookup by 'to' field (recipient) not 'from' (sender)
     * - Chat type is 'outbound' not 'inbound'
     * - Chat status is 'sent' not 'received'
     *
     * @param  array  $echo The echo data from webhook
     */
    protected function processSmbMessageEcho(array $echo): void
    {
        $wamId = $echo['id'] ?? null;
        $recipientPhone = $echo['to'] ?? null;
        $messageType = $echo['type'] ?? 'text';
        $timestamp = $echo['timestamp'] ?? time();

        // Validate required fields
        if (! $wamId || ! $recipientPhone) {
            Log::channel('webhook')->warning('Invalid SMB echo data - missing wam_id or recipient phone', [
                'organization_id' => $this->organizationId,
                'echo' => $echo,
            ]);

            return;
        }

        // AC-1.10.6: Idempotency check - prevent duplicate chat creation
        if ($this->isDuplicateMessage($wamId)) {
            Log::channel('webhook')->info('Duplicate SMB echo skipped', [
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
            ]);

            return;
        }

        // AC-1.10.1: Find or create contact by RECIPIENT phone (not sender)
        $contactService = new ContactService($this->organizationId);
        $result = $contactService->findOrCreateFromWebhook($recipientPhone, null);
        $contact = $result['contact'];
        $isNewContact = $result['isNew'];

        // Build metadata for the chat (reuse existing method)
        $metadata = $this->buildMessageMetadata($echo, $messageType);

        // AC-1.10.2: Create chat record with type='outbound' (different from inbound)
        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'outbound',
            'metadata' => json_encode($metadata),
            'status' => 'sent',
            'is_read' => 1, // Outbound messages are considered "read"
            'created_at' => now(),
        ]);

        // AC-1.10.4: Create ChatLog entry for chat thread view
        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        Log::channel('webhook')->info('SMB echo chat created', [
            'organization_id' => $this->organizationId,
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'message_type' => $messageType,
        ]);

        // AC-1.10.3: Dispatch DownloadWebhookMediaJob for media messages
        if ($this->hasMedia($echo, $messageType)) {
            $mediaData = $this->extractMediaData($echo, $messageType);
            DownloadWebhookMediaJob::dispatch(
                $chat->id,
                $contact->id,
                $this->organizationId,
                $mediaData
            )->onQueue('webhook-high');

            Log::channel('webhook')->info('DownloadWebhookMediaJob dispatched for SMB echo', [
                'chat_id' => $chat->id,
                'media_type' => $messageType,
            ]);
        }

        // AC-1.10.5: Dispatch ProcessWebhookNotificationsJob for real-time UI updates
        // Pass raw WhatsApp payload for backward-compatible external webhooks
        ProcessWebhookNotificationsJob::dispatch(
            $chat->id,
            $contact->id,
            $this->organizationId,
            $isNewContact,
            $this->payload
        )->onQueue('webhook-high');

        Log::channel('webhook')->info('ProcessWebhookNotificationsJob dispatched for SMB echo', [
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
        ]);
    }

    /**
     * Process SMB App State Sync webhooks (contact sync from WhatsApp Business App).
     *
     * SMB App State Sync webhooks are sent by Meta when contacts are created, updated,
     * or deleted in the WhatsApp Business App. This enables bidirectional contact sync
     * between the Business App and Chatberry.
     *
     * AC-1.11.1: Check organization metadata.sync_contacts flag before processing
     * AC-1.11.2: Contact 'create' action creates new contact in Chatberry
     * AC-1.11.3: Contact 'update' action updates contact name if empty
     * AC-1.11.4: Contact 'delete' action soft-deletes contact
     * AC-1.11.5: New contacts trigger ticket assignment via ChatService
     * AC-1.11.6: External webhook 'smb.app.state.sync' triggered
     */
    protected function processSmbAppStateSync(): void
    {
        $value = $this->payload['value'] ?? [];
        $stateSync = $value['state_sync'] ?? [];

        if (empty($stateSync)) {
            Log::channel('webhook')->debug('SMB app state sync - empty payload', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        // AC-1.11.1: Check organization metadata.sync_contacts flag
        $organization = Organization::find($this->organizationId);
        if (! $organization) {
            Log::channel('webhook')->warning('SMB app state sync - organization not found', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $metadata = json_decode($organization->metadata, true) ?? [];
        $syncEnabled = $metadata['sync_contacts'] ?? 0;

        if (! $syncEnabled) {
            Log::channel('webhook')->info('SMB app state sync - contact sync disabled for organization', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        Log::channel('webhook')->info('Processing SMB app state sync', [
            'organization_id' => $this->organizationId,
            'sync_item_count' => count($stateSync),
        ]);

        $processedItems = [];

        foreach ($stateSync as $syncItem) {
            $result = $this->processSmbStateSyncItem($syncItem, $organization);
            if ($result) {
                $processedItems[] = $result;
            }
        }

        // AC-1.11.6: Trigger external webhook for SMB app state sync (fire-and-forget)
        if (! empty($processedItems)) {
            try {
                WebhookHelper::triggerWebhookEvent('smb.app.state.sync', [
                    'data' => [
                        'processed_count' => count($processedItems),
                        'items' => $processedItems,
                    ],
                ], $this->organizationId);
            } catch (\Throwable $e) {
                // Fire-and-forget - don't fail on webhook error
                Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                    'event' => 'smb.app.state.sync',
                    'organization_id' => $this->organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single SMB state sync item.
     *
     * @param  array  $syncItem The sync item from webhook payload
     * @param  Organization  $organization The organization instance
     * @return array|null Processed item details or null if skipped
     */
    protected function processSmbStateSyncItem(array $syncItem, Organization $organization): ?array
    {
        $type = $syncItem['type'] ?? null;
        $action = $syncItem['action'] ?? null;

        // Only process contact type sync items
        if ($type !== 'contact') {
            Log::channel('webhook')->debug('SMB state sync - skipping non-contact type', [
                'organization_id' => $this->organizationId,
                'type' => $type,
            ]);

            return null;
        }

        $contactInfo = $syncItem['contact'] ?? [];
        $phone = $contactInfo['phone_number'] ?? null;
        $fullName = $contactInfo['full_name'] ?? null;
        $firstName = $contactInfo['first_name'] ?? null;

        if (! $phone) {
            Log::channel('webhook')->warning('SMB state sync - missing phone number', [
                'organization_id' => $this->organizationId,
                'sync_item' => $syncItem,
            ]);

            return null;
        }

        // Format phone to E.164
        $formattedPhone = \App\Services\PhoneService::getE164Format($phone);
        if (! $formattedPhone) {
            $formattedPhone = \App\Services\PhoneService::normalize($phone);
        }

        // Find existing contact
        $contact = \App\Models\Contact::where('organization_id', $this->organizationId)
            ->where(function ($query) use ($formattedPhone, $phone) {
                $query->where('phone', $formattedPhone)
                    ->orWhere('phone', '+'.$phone)
                    ->orWhere('phone', $phone);
            })
            ->first();

        $result = [
            'phone' => $formattedPhone,
            'action' => $action,
            'success' => false,
        ];

        // AC-1.11.2, AC-1.11.3, AC-1.11.4: Handle actions
        switch ($action) {
            case 'create':
                $result = $this->handleStateSyncCreate($contact, $formattedPhone, $firstName, $fullName);
                break;

            case 'update':
                $result = $this->handleStateSyncUpdate($contact, $firstName, $formattedPhone);
                break;

            case 'delete':
                $result = $this->handleStateSyncDelete($contact, $formattedPhone);
                break;

            default:
                Log::channel('webhook')->warning('SMB state sync - unknown action', [
                    'organization_id' => $this->organizationId,
                    'action' => $action,
                ]);
                $result['error'] = 'unknown_action';
        }

        return $result;
    }

    /**
     * Handle contact 'create' action from SMB state sync.
     *
     * AC-1.11.2: Creates new contact if not exists
     * AC-1.11.5: Triggers ticket assignment for new contacts
     *
     * @return array Result details
     */
    protected function handleStateSyncCreate(
        ?\App\Models\Contact $existingContact,
        string $phone,
        ?string $firstName,
        ?string $fullName
    ): array {
        $result = [
            'phone' => $phone,
            'action' => 'create',
            'success' => true,
        ];

        if ($existingContact) {
            // Contact already exists - use existing
            $result['contact_id'] = $existingContact->id;
            $result['is_new'] = false;

            Log::channel('webhook')->info('SMB state sync create - contact exists', [
                'organization_id' => $this->organizationId,
                'contact_id' => $existingContact->id,
                'phone' => $phone,
            ]);

            return $result;
        }

        // Parse name
        $nameParts = $fullName ? explode(' ', $fullName, 2) : [];
        $parsedFirstName = $firstName ?? ($nameParts[0] ?? $phone);
        $parsedLastName = $nameParts[1] ?? '';

        // Create new contact
        $contact = \App\Models\Contact::create([
            'organization_id' => $this->organizationId,
            'phone' => $phone,
            'first_name' => $parsedFirstName,
            'last_name' => $parsedLastName,
            'is_subscribed' => 1,
            'created_by' => 0, // System created
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result['contact_id'] = $contact->id;
        $result['is_new'] = true;

        Log::channel('webhook')->info('SMB state sync create - contact created', [
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'phone' => $phone,
        ]);

        // AC-1.11.5: Trigger ticket assignment for new contacts
        try {
            $chatService = new ChatService($this->organizationId);
            $chatService->handleTicketAssignment($contact->id);

            Log::channel('webhook')->info('SMB state sync - ticket assignment triggered', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
            ]);
        } catch (\Throwable $e) {
            // Non-blocking - don't fail the job on ticket assignment error
            Log::channel('webhook')->warning('SMB state sync - ticket assignment failed (non-blocking)', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Trigger webhook for new contact
        try {
            $cleanContact = $contact->makeHidden(['id', 'organization_id', 'created_by']);
            WebhookHelper::triggerWebhookEvent('contact.created', $cleanContact, $this->organizationId);
        } catch (\Throwable $e) {
            // Fire-and-forget
            Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                'event' => 'contact.created',
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Handle contact 'update' action from SMB state sync.
     *
     * AC-1.11.3: Updates contact name only if currently empty
     *
     * @return array Result details
     */
    protected function handleStateSyncUpdate(?\App\Models\Contact $contact, ?string $firstName, string $phone): array
    {
        $result = [
            'phone' => $phone,
            'action' => 'update',
            'success' => true,
        ];

        if (! $contact) {
            Log::channel('webhook')->info('SMB state sync update - contact not found, skipping', [
                'organization_id' => $this->organizationId,
                'phone' => $phone,
            ]);
            $result['success'] = false;
            $result['error'] = 'contact_not_found';

            return $result;
        }

        $result['contact_id'] = $contact->id;
        $result['updated'] = false;

        // AC-1.11.3: Only update name if currently empty
        if (empty($contact->first_name) && ! empty($firstName)) {
            $contact->update([
                'first_name' => $firstName,
                'updated_at' => now(),
            ]);

            $result['updated'] = true;
            $result['field'] = 'first_name';

            Log::channel('webhook')->info('SMB state sync update - contact name updated', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'new_first_name' => $firstName,
            ]);
        } else {
            Log::channel('webhook')->debug('SMB state sync update - no update needed', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'existing_first_name' => $contact->first_name,
            ]);
        }

        return $result;
    }

    /**
     * Handle contact 'delete' action from SMB state sync.
     *
     * AC-1.11.4: Soft-deletes contact by setting deleted_at timestamp
     *
     * @return array Result details
     */
    protected function handleStateSyncDelete(?\App\Models\Contact $contact, string $phone): array
    {
        $result = [
            'phone' => $phone,
            'action' => 'delete',
            'success' => true,
        ];

        if (! $contact) {
            Log::channel('webhook')->info('SMB state sync delete - contact not found, skipping', [
                'organization_id' => $this->organizationId,
                'phone' => $phone,
            ]);
            $result['success'] = false;
            $result['error'] = 'contact_not_found';

            return $result;
        }

        $result['contact_id'] = $contact->id;

        // AC-1.11.4: Soft delete by setting deleted_at
        $contact->deleted_at = now();
        $contact->save();

        Log::channel('webhook')->info('SMB state sync delete - contact soft-deleted', [
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'phone' => $phone,
        ]);

        return $result;
    }

    /**
     * Process history webhooks (historical messages from WhatsApp Business App).
     *
     * History webhooks are sent by Meta when an organization enables coexistence mode
     * and opts to import historical conversations. This can include thousands of messages
     * delivered in chunks with progress metadata.
     *
     * AC-1.12.1: Check organization metadata.sync_history flag before processing
     * AC-1.12.2: Parse history threads and messages from payload
     * AC-1.12.3: Create contacts from thread IDs (phone numbers)
     * AC-1.12.4: Import messages with original timestamps
     * AC-1.12.5: Handle both inbound and outbound historical messages
     * AC-1.12.6: Duplicate messages (same wam_id) are skipped
     * AC-1.12.7: Progress metadata logged for monitoring
     */
    protected function processHistory(): void
    {
        $value = $this->payload['value'] ?? [];
        $history = $value['history'] ?? [];

        if (empty($history)) {
            Log::channel('webhook')->debug('History webhook - empty payload', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        // AC-1.12.1: Check organization metadata.sync_history flag
        $organization = Organization::find($this->organizationId);
        if (! $organization) {
            Log::channel('webhook')->warning('History webhook - organization not found', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $metadata = json_decode($organization->metadata, true) ?? [];

        // Check BOTH possible locations for sync_history flag
        $syncEnabled = $metadata['sync_history'] ?? ($metadata['whatsapp']['sync_history'] ?? 0);

        if (! $syncEnabled) {
            Log::channel('webhook')->info('History webhook - sync_history disabled for organization', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        Log::channel('webhook')->info('Processing history webhook', [
            'organization_id' => $this->organizationId,
            'chunk_count' => count($history),
        ]);

        $totalMessagesImported = 0;
        $totalMessagesSkipped = 0;

        foreach ($history as $chunk) {
            $result = $this->processHistoryChunk($chunk);
            $totalMessagesImported += $result['imported'];
            $totalMessagesSkipped += $result['skipped'];
        }

        // AC-1.12.7: Log completion summary
        Log::channel('webhook')->info('History webhook processing complete', [
            'organization_id' => $this->organizationId,
            'total_imported' => $totalMessagesImported,
            'total_skipped' => $totalMessagesSkipped,
        ]);

        // Trigger external webhook for history import
        try {
            WebhookHelper::triggerWebhookEvent('history.import.complete', [
                'data' => [
                    'messages_imported' => $totalMessagesImported,
                    'messages_skipped' => $totalMessagesSkipped,
                ],
            ], $this->organizationId);
        } catch (\Throwable $e) {
            // Fire-and-forget - don't fail on webhook error
            Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                'event' => 'history.import.complete',
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a single history chunk containing threads and metadata.
     *
     * @param  array  $chunk The history chunk data
     * @return array Result with imported and skipped counts
     */
    protected function processHistoryChunk(array $chunk): array
    {
        $threads = $chunk['threads'] ?? [];
        $metadata = $chunk['metadata'] ?? [];

        // AC-1.12.7: Log progress metadata for monitoring
        $phase = $metadata['phase'] ?? null;
        $progress = $metadata['progress'] ?? null;

        if ($phase || $progress) {
            Log::channel('webhook')->info('History chunk progress', [
                'organization_id' => $this->organizationId,
                'phase' => $phase,
                'progress' => $progress,
                'thread_count' => count($threads),
            ]);
        }

        $result = [
            'imported' => 0,
            'skipped' => 0,
        ];

        foreach ($threads as $thread) {
            $threadResult = $this->processHistoryThread($thread);
            $result['imported'] += $threadResult['imported'];
            $result['skipped'] += $threadResult['skipped'];
        }

        return $result;
    }

    /**
     * Process a single history thread (contact and their messages).
     *
     * @param  array  $thread The thread data containing id (phone) and messages
     * @return array Result with imported and skipped counts
     */
    protected function processHistoryThread(array $thread): array
    {
        $threadId = $thread['id'] ?? null; // Thread ID is the phone number
        $messages = $thread['messages'] ?? [];

        if (! $threadId) {
            Log::channel('webhook')->warning('History thread - missing thread ID', [
                'organization_id' => $this->organizationId,
                'thread' => $thread,
            ]);

            return ['imported' => 0, 'skipped' => 0];
        }

        // AC-1.12.3: Find or create contact from thread ID (phone number)
        $contactService = new ContactService($this->organizationId);
        $result = $contactService->findOrCreateFromWebhook($threadId, null);
        $contact = $result['contact'];
        $isNewContact = $result['isNew'];

        if ($isNewContact) {
            Log::channel('webhook')->info('History import - new contact created', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'phone' => $threadId,
            ]);
        }

        $importResult = [
            'imported' => 0,
            'skipped' => 0,
        ];

        foreach ($messages as $message) {
            $messageImported = $this->processHistoryMessage($message, $contact, $isNewContact);
            if ($messageImported) {
                $importResult['imported']++;
            } else {
                $importResult['skipped']++;
            }
        }

        return $importResult;
    }

    /**
     * Process a single history message.
     *
     * Key differences from regular messages:
     * - Uses 'direction' field to determine inbound vs outbound
     * - Preserves original timestamp using Carbon::createFromTimestamp
     * - Logs external webhook as 'history.message.received'
     *
     * @param  array  $message The message data from history webhook
     * @param  \App\Models\Contact  $contact The contact for this message
     * @param  bool  $isNewContact Whether the contact was just created
     * @return bool True if message was imported, false if skipped (duplicate)
     */
    protected function processHistoryMessage(array $message, $contact, bool $isNewContact): bool
    {
        $wamId = $message['id'] ?? null;
        $direction = $message['direction'] ?? 'inbound';
        $messageType = $message['type'] ?? 'text';
        $timestamp = $message['timestamp'] ?? time();

        // Validate required fields
        if (! $wamId) {
            Log::channel('webhook')->warning('History message - missing wam_id', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'message' => $message,
            ]);

            return false;
        }

        // AC-1.12.6: Idempotency check - prevent duplicate chat creation
        if ($this->isDuplicateMessage($wamId)) {
            Log::channel('webhook')->debug('History message - duplicate skipped', [
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
            ]);

            return false;
        }

        // AC-1.12.5: Determine type from direction field
        $chatType = ($direction === 'outbound') ? 'outbound' : 'inbound';
        $chatStatus = ($direction === 'outbound') ? 'sent' : 'received';
        $isRead = ($direction === 'outbound') ? 1 : 0;

        // Build metadata (reuse existing method)
        $metadata = $this->buildMessageMetadata($message, $messageType);

        // AC-1.12.4: Create chat with original timestamp
        $originalTimestamp = Carbon::createFromTimestamp($timestamp);

        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => $chatType,
            'metadata' => json_encode($metadata),
            'status' => $chatStatus,
            'is_read' => $isRead,
            'created_at' => $originalTimestamp,
        ]);

        // Create ChatLog entry with original timestamp
        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => $originalTimestamp,
        ]);

        Log::channel('webhook')->debug('History message imported', [
            'organization_id' => $this->organizationId,
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'direction' => $direction,
            'message_type' => $messageType,
            'original_timestamp' => $originalTimestamp->toIso8601String(),
        ]);

        // Dispatch DownloadWebhookMediaJob for media messages
        if ($this->hasMedia($message, $messageType)) {
            $mediaData = $this->extractMediaData($message, $messageType);
            DownloadWebhookMediaJob::dispatch(
                $chat->id,
                $contact->id,
                $this->organizationId,
                $mediaData
            )->onQueue('webhook-high');

            Log::channel('webhook')->debug('DownloadWebhookMediaJob dispatched for history message', [
                'chat_id' => $chat->id,
                'media_type' => $messageType,
            ]);
        }

        // Dispatch ProcessWebhookNotificationsJob for real-time UI updates
        // Pass raw WhatsApp payload for backward-compatible external webhooks
        ProcessWebhookNotificationsJob::dispatch(
            $chat->id,
            $contact->id,
            $this->organizationId,
            $isNewContact,
            $this->payload
        )->onQueue('webhook-high');

        // Trigger external webhook for history message (fire-and-forget)
        try {
            WebhookHelper::triggerWebhookEvent('history.message.received', [
                'data' => [
                    'chat_id' => $chat->id,
                    'contact_id' => $contact->id,
                    'wam_id' => $wamId,
                    'message_type' => $messageType,
                    'direction' => $direction,
                ],
            ], $this->organizationId);
        } catch (\Throwable $e) {
            // Fire-and-forget - don't fail on webhook error
            Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                'event' => 'history.message.received',
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Process template status updates (approved, rejected, etc).
     */
    protected function processTemplateStatusUpdate(): void
    {
        $value = $this->payload['value'] ?? [];

        Log::channel('webhook')->info('Template status update received', [
            'organization_id' => $this->organizationId,
            'value' => $value,
        ]);

        // Template status updates can be handled by the TemplateService
        // This is typically handled separately from message processing
    }

    /**
     * Process account-level updates (quality, capability, etc).
     */
    protected function processAccountUpdate(): void
    {
        Log::channel('webhook')->info('Account update received', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'payload' => $this->payload,
        ]);

        // Account updates can trigger notifications to admins
        // or update organization metadata as needed
    }

    /**
     * Log unknown webhook type for debugging.
     */
    protected function logUnknownWebhookType(): void
    {
        Log::channel('webhook')->warning('Unknown webhook type received', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'payload' => $this->payload,
        ]);
    }

    /**
     * Process pending MissedChatStatusUpdates when chat is created.
     *
     * AC-1.7.4: When chat is created, pending MissedChatStatusUpdates are processed.
     * AC-1.7.5: Processed MissedChatStatusUpdates are marked state=PROCESSED.
     * AC-1.7.6: External webhook 'message.status.update' triggered after status update.
     *
     * @param  Chat  $chat The newly created chat record
     * @param  \App\Models\Contact  $contact The contact associated with the chat
     */
    protected function processPendingStatusUpdates(Chat $chat, $contact): void
    {
        // Query for pending status updates for this wam_id
        $pendingUpdates = MissedChatStatusUpdate::where('organization_id', $this->organizationId)
            ->where('wam_id', $chat->wam_id)
            ->where('state', MissedChatStatusUpdate::STATE_NOT_PROCESSED)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($pendingUpdates->isEmpty()) {
            return;
        }

        Log::channel('webhook')->info('Processing pending MissedChatStatusUpdates', [
            'chat_id' => $chat->id,
            'wam_id' => $chat->wam_id,
            'count' => $pendingUpdates->count(),
        ]);

        $latestStatus = null;

        foreach ($pendingUpdates as $pendingUpdate) {
            try {
                // AC-1.7.5: Create ChatStatusLog entry
                // Note: chat_status_logs table only has: id, chat_id, metadata, created_at
                $payload = json_decode($pendingUpdate->payload, true);
                $timestamp = $payload['timestamp'] ?? time();

                ChatStatusLog::create([
                    'chat_id' => $chat->id,
                    'metadata' => json_encode(array_merge($payload, [
                        'status' => $pendingUpdate->status,
                        'timestamp' => $timestamp,
                    ])),
                    'created_at' => now(),
                ]);

                // Track the latest status to update chat
                $latestStatus = $pendingUpdate->status;

                // AC-1.7.5: Mark as PROCESSED
                $pendingUpdate->update([
                    'state' => MissedChatStatusUpdate::STATE_PROCESSED,
                    'updated_at' => now(),
                ]);

                Log::channel('webhook')->debug('MissedChatStatusUpdate processed', [
                    'id' => $pendingUpdate->id,
                    'wam_id' => $pendingUpdate->wam_id,
                    'status' => $pendingUpdate->status,
                ]);
            } catch (\Throwable $e) {
                Log::channel('webhook')->warning('Failed to process MissedChatStatusUpdate (non-blocking)', [
                    'id' => $pendingUpdate->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue processing other updates - don't fail the main job
            }
        }

        // Update chat status to the latest received status
        if ($latestStatus) {
            $chat->update(['status' => $latestStatus]);

            // AC-1.7.6: Trigger external webhook for the status update
            try {
                WebhookHelper::triggerWebhookEvent('message.status.update', [
                    'data' => [
                        'status' => $latestStatus,
                        'wam_id' => $chat->wam_id,
                        'chat_id' => $chat->id,
                        'contact_id' => $contact->id,
                        'timestamp' => time(),
                    ],
                ], $this->organizationId);
            } catch (\Throwable $e) {
                // Fire-and-forget - don't fail on webhook error
                Log::channel('webhook')->warning('External webhook trigger failed (non-blocking)', [
                    'event' => 'message.status.update',
                    'chat_id' => $chat->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * Creates a WebhookFailure record for monitoring and manual retry capability.
     * AC-1.5.2: After 3 failed attempts, webhook_failures record is created with full payload.
     *
     * @param  \Throwable  $exception The exception that caused the failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('ProcessWebhookJob failed permanently', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // AC-1.5.2: Log to webhook_failures table for monitoring and manual retry
        try {
            WebhookFailure::create([
                'organization_id' => $this->organizationId,
                'job_type' => 'ProcessWebhookJob',
                'error_message' => substr($exception->getMessage(), 0, 1000),
                'error_code' => $exception->getCode() ? (string) $exception->getCode() : null,
                'payload' => $this->payload, // Model will cast to JSON
                'attempts' => $this->attempts(),
            ]);
        } catch (\Exception $e) {
            // Fail-safe: don't let logging failure mask original exception
            Log::channel('webhook')->error('Failed to log webhook failure to database', [
                'original_error' => $exception->getMessage(),
                'logging_error' => $e->getMessage(),
            ]);
        }
    }
}
