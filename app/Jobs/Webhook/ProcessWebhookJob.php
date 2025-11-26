<?php

namespace App\Jobs\Webhook;

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatStatusLog;
use App\Models\WebhookFailure;
use App\Services\AutoReplyService;
use App\Services\ChatService;
use App\Services\ContactService;
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
        ProcessWebhookNotificationsJob::dispatch(
            $chat->id,
            $contact->id,
            $this->organizationId,
            $isNewContact
        )->onQueue('webhook-high');

        Log::channel('webhook')->info('ProcessWebhookNotificationsJob dispatched', [
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
        ]);

        // Handle ticket assignment (moved to notification job to avoid blocking)
        // The ChatService::handleTicketAssignment will be called by notification job

        // Auto-reply integration - check triggers after chat creation
        // This is fire-and-forget; failures don't fail the main job
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
            // Chat not found - might be for outbound message not yet synced
            // Store for later processing (missed status update pattern)
            Log::channel('webhook')->debug('Status update for unknown chat', [
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
                'status' => $statusType,
            ]);

            return;
        }

        // Update chat status
        $chat->update(['status' => $statusType]);

        // Create status log entry
        ChatStatusLog::create([
            'chat_id' => $chat->id,
            'status' => $statusType,
            'timestamp' => $timestamp,
            'metadata' => json_encode($status),
            'created_at' => now(),
        ]);

        Log::channel('webhook')->info('Chat status updated', [
            'chat_id' => $chat->id,
            'wam_id' => $wamId,
            'status' => $statusType,
        ]);
    }

    /**
     * Process SMB message echoes (sent message confirmations).
     */
    protected function processSmbMessageEchoes(): void
    {
        // SMB echoes are typically for confirming sent messages
        // Implementation depends on specific business requirements
        Log::channel('webhook')->info('SMB message echo received', [
            'organization_id' => $this->organizationId,
            'payload' => $this->payload,
        ]);
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
