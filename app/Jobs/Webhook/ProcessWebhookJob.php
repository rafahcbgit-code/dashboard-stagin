<?php

namespace App\Jobs\Webhook;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatStatusLog;
use App\Models\MissedChatStatusUpdate;
use App\Models\Organization;
use App\Models\WebhookFailure;
use App\Services\AutoReplyService;
use App\Services\BotSettingsService;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\SubscriptionService;
use App\Services\SubscriptionUsageService;
use App\Services\UnsubscribeChecker;
use App\Services\Meta\WhatsApp\TemplateStatusWebhookProcessor;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\MetaFlows\Services\MetaFlowService;

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

    private const CHAT_METADATA_SAFE_BYTES = 60000;

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
     * Extra webhook envelope metadata, such as WABA ID and the full raw payload.
     */
    public array $context = [];

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 5;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 120;

    /**
     * The number of seconds to wait before retrying the job.
     * Exponential backoff: 1 min, 5 min, 15 min
     */
    public array $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload, int $organizationId, string $webhookType, array $context = [])
    {
        $this->payload = $payload;
        $this->organizationId = $organizationId;
        $this->webhookType = $webhookType;
        $this->context = $context;
        $this->context['dispatched_at'] ??= now()->toIso8601String();
        $this->onQueue(self::queueForType($webhookType));
    }

    public static function queueForType(string $webhookType): string
    {
        return in_array($webhookType, ['history', 'smb_app_state_sync'], true)
            ? 'webhook-bulk'
            : 'webhook-high';
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
                    ->expireAfter(180)
                    ->releaseAfter(15),
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
        $startedAt = now();
        $dispatchedAt = $this->context['dispatched_at'] ?? null;
        $dispatchToHandleMs = null;
        $contactPhone = $this->extractContactPhone();

        if ($dispatchedAt) {
            try {
                $dispatchToHandleMs = Carbon::parse($dispatchedAt)->diffInMilliseconds($startedAt, false);
            } catch (\Throwable) {
                $dispatchToHandleMs = null;
            }
        }

        Log::channel('webhook')->info('ProcessWebhookJob started', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'job_id' => $this->job?->getJobId(),
            'queue' => $this->queue,
            'attempt' => $this->attempts(),
            'dispatched_at' => $dispatchedAt,
            'started_at' => $startedAt->toIso8601String(),
            'dispatch_to_handle_ms' => $dispatchToHandleMs,
            'wam_id' => $this->extractFirstWamId(),
            'contact_phone' => $contactPhone,
            'overlap_lock_key' => $contactPhone ? "webhook-contact-{$this->organizationId}-{$contactPhone}" : null,
            'overlap_release_after_seconds' => 15,
            'overlap_expire_after_seconds' => 180,
            'payload' => $this->payload, // Full webhook payload for debugging
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

            $this->markWebhookFailureResolved();

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

    protected function markWebhookFailureResolved(): void
    {
        $failureId = $this->context['webhook_failure_id'] ?? null;

        if (! $failureId || ! Schema::hasColumn('webhook_failures', 'resolved_at')) {
            return;
        }

        try {
            WebhookFailure::whereKey($failureId)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to mark webhook failure as resolved', [
                'webhook_failure_id' => $failureId,
                'organization_id' => $this->organizationId,
                'webhook_type' => $this->webhookType,
                'error' => $e->getMessage(),
            ]);
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
        // Uses idx_chats_wam_org index for efficient lookup
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

        // Blocked contacts should not have inbound messages stored.
        if ((bool) ($contact->is_blocked ?? false)) {
            Log::channel('webhook')->info('Inbound message ignored for blocked contact', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'wam_id' => $wamId,
            ]);

            return;
        }

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

        $contactsSync = $this->syncSharedContactsFromMessage($message);

        // Build metadata for the chat
        $metadata = $this->buildMessageMetadata($message, $messageType);
        $this->attachContactsSyncMetadata($metadata, $contactsSync);

        // AC-1.2.2: Create chat record
        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'inbound',
            'metadata' => $this->encodeChatMetadata($metadata),
            'status' => 'received',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        (new SubscriptionUsageService())->ensureConversationWindowForInbound(
            (int) $this->organizationId,
            (int) $contact->id,
            (int) $chat->id
        );

        // Create ChatLog entry for the chat thread view
        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        // When the user submits a Meta Flow, Meta sends an interactive message with
        // type nfm_reply. Store the response so it can be reviewed in the dashboard.
        if ($messageType === 'interactive') {
            $this->processMetaFlowResponse($message, $contact->id);
        }

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
            )->onQueue('webhook-media');

            Log::channel('webhook')->info('DownloadWebhookMediaJob dispatched', [
                'chat_id' => $chat->id,
                'media_type' => $messageType,
            ]);
        }

        // Check if referral contains media (video_url and media_type)
        if (!empty($message['referral']['media_type']) && !empty($message['referral']['video_url'])) {
            $referralMediaData = [
                'type' => 'referral_video',
                'media_id' => null,
                'mime_type' => $message['referral']['media_type'] ?? 'video/mp4',
                'sha256' => null,
                'filename' => null,
                'caption' => $message['referral']['body'] ?? null,
                'video_url' => $message['referral']['video_url'],
                'thumbnail_url' => $message['referral']['thumbnail_url'] ?? null,
            ];

            DownloadWebhookMediaJob::dispatch(
                $chat->id,
                $contact->id,
                $this->organizationId,
                $referralMediaData
            )->onQueue('webhook-media');

            Log::channel('webhook')->info('DownloadWebhookMediaJob dispatched for referral media', [
                'chat_id' => $chat->id,
                'media_type' => 'referral_video',
                'video_url' => $message['referral']['video_url'],
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
        )->onQueue('webhook-notifications');

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
        // Only process auto-reply for supported message types.
        // Image captions are treated as the user's text when present.
        $autoReplyTypes = ['text', 'button', 'audio', 'interactive'];
        $hasImageCaption = $messageType === 'image'
            && trim((string) ($message['image']['caption'] ?? '')) !== '';

        $botSettingsService = new BotSettingsService();
        $automationEnabled = $botSettingsService->isAutomationEnabled($this->organizationId);

        if ($isMessageLimitReached) {
            Log::channel('webhook')->info('Auto-reply skipped - message limit reached', [
                'organization_id' => $this->organizationId,
                'chat_id' => $chat->id,
                'contact_id' => $contact->id,
            ]);
        } elseif (! $automationEnabled) {
            // Org-level automation is off — skip all auto-reply, but still
            // process interactive button clicks so users aren't stuck.
            $metadata = json_decode($chat->metadata, true);
            $isInteractiveButton = isset($metadata['type']) && $metadata['type'] === 'interactive'
                && isset($metadata['interactive']['type']) && $metadata['interactive']['type'] === 'button_reply';

            if ($isInteractiveButton) {
                try {
                    $autoReplyService = new AutoReplyService($this->payload);
                    $autoReplyService->checkAutoReply($chat, $isNewContact);
                } catch (\Throwable $e) {
                    Log::warning('Auto-reply for interactive button failed (non-blocking)', [
                        'chat_id' => $chat->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::channel('webhook')->info('Auto-reply skipped - automation disabled for organization', [
                    'organization_id' => $this->organizationId,
                    'chat_id' => $chat->id,
                ]);
            }
        } elseif (! $contact->bot_enable) {
            // Bot is disabled for this contact, but interactive button clicks
            // (fallback menu, AI feedback) must still be processed to avoid
            // leaving users stuck with no way to interact.
            $metadata = json_decode($chat->metadata, true);
            $isInteractiveButton = isset($metadata['type']) && $metadata['type'] === 'interactive'
                && isset($metadata['interactive']['type']) && $metadata['interactive']['type'] === 'button_reply';

            if ($isInteractiveButton) {
                try {
                    $autoReplyService = new AutoReplyService($this->payload);
                    $autoReplyService->checkAutoReply($chat, $isNewContact);
                } catch (\Throwable $e) {
                    Log::warning('Auto-reply for interactive button failed (non-blocking)', [
                        'chat_id' => $chat->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::channel('webhook')->info('Auto-reply skipped - bot disabled for contact', [
                    'chat_id' => $chat->id,
                    'contact_id' => $contact->id,
                ]);
            }
        } elseif (! in_array($messageType, $autoReplyTypes) && ! $hasImageCaption) {
            Log::channel('webhook')->debug('Auto-reply skipped - unsupported message type', [
                'chat_id' => $chat->id,
                'message_type' => $messageType,
            ]);
        } else {

            try {
                $autoReplyService = new AutoReplyService($this->payload);
                $autoReplyService->checkAutoReply($chat, $isNewContact);
            } catch (\Throwable $e) {
                // Catch any unexpected exception to ensure the message flow
                // is not interrupted by auto-reply logic failures
                Log::warning('Auto-reply check failed (non-blocking)', [
                    'chat_id' => $chat->id,
                    'file'    => $e->getFile(),
                    'error'   => $e->getMessage(),
                    'exception' => get_class($e),
                    'line'    => $e->getLine(),
                ]);
            }
        }
    }

    /**
     * Check if a message with this wam_id already exists (idempotency).
     *
     * AC-1.2.5: Utilizes idx_chats_wam_org index for efficient lookup.
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

    protected function extractFirstWamId(): ?string
    {
        $value = $this->payload['value'] ?? [];

        foreach (['messages', 'statuses', 'message_echoes'] as $key) {
            $items = $value[$key] ?? [];

            if (! is_array($items) || empty($items)) {
                continue;
            }

            $wamId = $items[0]['id'] ?? null;
            if (is_string($wamId) && $wamId !== '') {
                return $wamId;
            }
        }

        return null;
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
                $metadata['text'] = [
                    'body' => $message['text']['body'] ?? '',
                ];

                if (!empty($message['referral'])) {
                    $metadata['referral'] = [
                        'headline' => $message['referral']['headline'] ?? null,
                        'body'     => $message['referral']['body'] ?? null,
                        'source_type'     => $message['referral']['source_type'] ?? null,
                        'source_id'     => $message['referral']['source_id'] ?? null,
                        'media_type'     => $message['referral']['media_type'] ?? null,
                        'video_url'     => $message['referral']['video_url'] ?? null,
                        'thumbnail_url'     => $message['referral']['thumbnail_url'] ?? null,
                        'ctwa_clid'     => $message['referral']['ctwa_clid'] ?? null,
                    ];
                }
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

    protected function syncSharedContactsFromMessage(array $message): array
    {
        if (($message['type'] ?? null) !== 'contacts') {
            return [];
        }

        $sharedContacts = $message['contacts'] ?? [];
        if (! is_array($sharedContacts) || empty($sharedContacts)) {
            return [];
        }

        $contactService = new ContactService($this->organizationId);
        $stats = [
            'cards_count' => count($sharedContacts),
            'phones_count' => 0,
            'unique_phones_count' => 0,
            'created_count' => 0,
            'existing_count' => 0,
            'failed_count' => 0,
        ];
        $seenPhones = [];

        foreach ($sharedContacts as $sharedContact) {
            $name = $this->sharedContactDisplayName($sharedContact);
            $phones = $sharedContact['phones'] ?? [];

            if (! is_array($phones)) {
                continue;
            }

            foreach ($phones as $phone) {
                $stats['phones_count']++;

                $phoneValue = $phone['wa_id'] ?? $phone['phone'] ?? null;
                if (! is_string($phoneValue) || trim($phoneValue) === '') {
                    $stats['failed_count']++;
                    continue;
                }

                $phoneKey = preg_replace('/\D+/', '', $phoneValue) ?: trim($phoneValue);
                if (isset($seenPhones[$phoneKey])) {
                    continue;
                }

                $seenPhones[$phoneKey] = true;
                $stats['unique_phones_count']++;

                try {
                    $result = $contactService->findOrCreateFromWebhook($phoneValue, $name);
                    if ($result['isNew'] ?? false) {
                        $stats['created_count']++;
                    } else {
                        $stats['existing_count']++;
                    }
                } catch (\Throwable $exception) {
                    $stats['failed_count']++;

                    Log::channel('webhook')->warning('Shared contact could not be synced from contacts message', [
                        'organization_id' => $this->organizationId,
                        'phone' => $phoneValue,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $stats;
    }

    protected function sharedContactDisplayName(array $sharedContact): ?string
    {
        $name = $sharedContact['name'] ?? [];
        if (! is_array($name)) {
            return null;
        }

        $displayName = $name['formatted_name'] ?? null;
        if (is_string($displayName) && trim($displayName) !== '') {
            return trim($displayName);
        }

        $parts = array_filter([
            $name['first_name'] ?? null,
            $name['middle_name'] ?? null,
            $name['last_name'] ?? null,
        ], static fn ($part) => is_string($part) && trim($part) !== '');

        return ! empty($parts) ? trim(implode(' ', $parts)) : null;
    }

    protected function attachContactsSyncMetadata(array &$metadata, array $contactsSync): void
    {
        if (($metadata['type'] ?? null) !== 'contacts' || empty($contactsSync)) {
            return;
        }

        $metadata['contacts_sync'] = $contactsSync;
    }

    protected function encodeChatMetadata(array $metadata): string
    {
        $encoded = $this->jsonEncodeMetadata($metadata);
        if (strlen($encoded) <= self::CHAT_METADATA_SAFE_BYTES) {
            return $encoded;
        }

        if (($metadata['type'] ?? null) === 'contacts' && is_array($metadata['contacts'] ?? null)) {
            return $this->encodeContactsMetadataWithinLimit($metadata);
        }

        return $this->jsonEncodeMetadata([
            'type' => $metadata['type'] ?? 'unknown',
            'timestamp' => $metadata['timestamp'] ?? time(),
            'metadata_truncated' => true,
            'metadata_original_bytes' => strlen($encoded),
            'metadata_limit_bytes' => self::CHAT_METADATA_SAFE_BYTES,
        ]);
    }

    protected function encodeContactsMetadataWithinLimit(array $metadata): string
    {
        $contacts = $metadata['contacts'];
        $originalBytes = strlen($this->jsonEncodeMetadata($metadata));
        $originalCount = count($contacts);

        $baseMetadata = $metadata;
        $baseMetadata['contacts_metadata_truncated'] = true;
        $baseMetadata['contacts_metadata_original_count'] = $originalCount;
        $baseMetadata['contacts_metadata_original_bytes'] = $originalBytes;
        $baseMetadata['contacts_metadata_limit_bytes'] = self::CHAT_METADATA_SAFE_BYTES;

        $low = 0;
        $high = $originalCount;
        $bestEncoded = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = $baseMetadata;
            $candidate['contacts'] = array_slice($contacts, 0, $mid);
            $candidate['contacts_metadata_stored_count'] = $mid;
            $candidate['contacts_metadata_omitted_count'] = max(0, $originalCount - $mid);

            $encoded = $this->jsonEncodeMetadata($candidate);
            if (strlen($encoded) <= self::CHAT_METADATA_SAFE_BYTES) {
                $bestEncoded = $encoded;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $bestEncoded ?? $this->jsonEncodeMetadata([
            'type' => 'contacts',
            'timestamp' => $metadata['timestamp'] ?? time(),
            'contacts' => [],
            'contacts_sync' => $metadata['contacts_sync'] ?? null,
            'contacts_metadata_truncated' => true,
            'contacts_metadata_original_count' => $originalCount,
            'contacts_metadata_stored_count' => 0,
            'contacts_metadata_omitted_count' => $originalCount,
            'contacts_metadata_original_bytes' => $originalBytes,
            'contacts_metadata_limit_bytes' => self::CHAT_METADATA_SAFE_BYTES,
        ]);
    }

    protected function jsonEncodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        // Update chat status only when the incoming status progresses the current state.
        Chat::updateStatusIfProgressed($chat->id, $statusType);

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

        $this->recordDeliveredTemplateUsage($chat, $statusType, $status);

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

        try {
            if ($messageType === 'edit') {
                $this->processSmbMessageEdit($echo);

                return;
            }

            // Validate required fields
            if (! $wamId || ! $recipientPhone) {
                Log::channel('webhook')->warning('Invalid SMB echo data - missing wam_id or recipient phone', [
                    'organization_id' => $this->organizationId,
                    'echo' => $echo,
                ]);

                $this->logSmbEchoFailure($echo, 'missing_wam_or_recipient');

                return;
            }

            // AC-1.10.6: Idempotency check - prevent duplicate chat creation
            if ($this->isDuplicateMessage($wamId)) {
                Log::channel('webhook')->info('Duplicate SMB echo skipped', [
                    'organization_id' => $this->organizationId,
                    'wam_id' => $wamId,
                ]);

                $this->logSmbEchoFailure($echo, 'duplicate_wam_id');

                return;
            }

            // AC-1.10.1: Find or create contact by RECIPIENT phone (not sender)
            $contactService = new ContactService($this->organizationId);
            $result = $contactService->findOrCreateFromWebhook($recipientPhone, null);
            $contact = $result['contact'];
            $isNewContact = $result['isNew'];

            $contactsSync = $this->syncSharedContactsFromMessage($echo);

            // Build metadata for the chat (reuse existing method)
            $metadata = $this->buildMessageMetadata($echo, $messageType);
            $this->attachContactsSyncMetadata($metadata, $contactsSync);

            // AC-1.10.2: Create chat record with type='outbound' (different from inbound)
            try {
                $chat = Chat::create([
                    'organization_id' => $this->organizationId,
                    'contact_id' => $contact->id,
                    'wam_id' => $wamId,
                    'type' => 'outbound',
                    'metadata' => $this->encodeChatMetadata($metadata),
                    'status' => 'sent',
                    'is_read' => 1, // Outbound messages are considered "read"
                    'created_at' => now(),
                ]);
            } catch (\Throwable $chatException) {
                $this->logSmbEchoFailure($echo, 'chat_create_failed', $chatException);
                throw $chatException;
            }

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
                )->onQueue('webhook-media');

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
            )->onQueue('webhook-notifications');

            Log::channel('webhook')->info('ProcessWebhookNotificationsJob dispatched for SMB echo', [
                'chat_id' => $chat->id,
                'contact_id' => $contact->id,
            ]);
        } catch (\Throwable $exception) {
            $this->logSmbEchoFailure($echo, 'exception', $exception);

            throw $exception;
        }
    }

    /**
     * Process an SMB message edit by updating the original chat row.
     */
    protected function processSmbMessageEdit(array $echo): void
    {
        $editWamId = $echo['id'] ?? null;
        $recipientPhone = $echo['to'] ?? null;
        $editedAt = $echo['timestamp'] ?? time();
        $originalMessageId = $echo['edit']['original_message_id'] ?? null;
        $editedMessage = $echo['edit']['message'] ?? [];
        $editedMessageType = $editedMessage['type'] ?? null;
        $editedTextBody = $editedMessage['text']['body'] ?? null;

        if (! $editWamId || ! $recipientPhone || ! $originalMessageId || $editedMessageType !== 'text' || ! is_string($editedTextBody)) {
            Log::channel('webhook')->warning('Invalid SMB edit echo data', [
                'organization_id' => $this->organizationId,
                'echo' => $echo,
            ]);

            $this->logSmbEchoFailure($echo, 'invalid_edit_payload');

            return;
        }

        $chat = Chat::where('organization_id', $this->organizationId)
            ->where('wam_id', $originalMessageId)
            ->first();

        if ($chat) {
            $metadata = $this->decodeChatMetadata($chat->metadata);
            $metadata['type'] = 'text';
            $metadata['text'] = $metadata['text'] ?? [];
            $metadata['text']['body'] = $editedTextBody;
            $metadata['edited'] = $this->buildSmbEditMetadata($editWamId, $editedAt, $originalMessageId);

            $chat->metadata = $this->encodeChatMetadata($metadata);
            $chat->save();

            Log::channel('webhook')->info('SMB edit applied to existing chat', [
                'organization_id' => $this->organizationId,
                'chat_id' => $chat->id,
                'wam_id' => $originalMessageId,
                'edit_wam_id' => $editWamId,
            ]);

            $this->broadcastUpdatedSmbEchoChat($chat);

            return;
        }

        $contactService = new ContactService($this->organizationId);
        $result = $contactService->findOrCreateFromWebhook($recipientPhone, null);
        $contact = $result['contact'];
        $isNewContact = $result['isNew'];

        $metadata = $this->buildMessageMetadata([
            'id' => $originalMessageId,
            'timestamp' => $editedAt,
            'type' => 'text',
            'text' => ['body' => $editedTextBody],
        ], 'text');
        $metadata['edited'] = $this->buildSmbEditMetadata($editWamId, $editedAt, $originalMessageId);

        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $originalMessageId,
            'type' => 'outbound',
            'metadata' => $this->encodeChatMetadata($metadata),
            'status' => 'sent',
            'is_read' => 1,
            'created_at' => now(),
        ]);

        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        ProcessWebhookNotificationsJob::dispatch(
            $chat->id,
            $contact->id,
            $this->organizationId,
            $isNewContact,
            $this->payload
        )->onQueue('webhook-notifications');

        Log::channel('webhook')->info('SMB edit created chat using original wam_id', [
            'organization_id' => $this->organizationId,
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
            'wam_id' => $originalMessageId,
            'edit_wam_id' => $editWamId,
        ]);
    }

    protected function buildSmbEditMetadata(string $editWamId, string|int $editedAt, string $originalMessageId): array
    {
        return [
            'is_edited' => true,
            'edit_wam_id' => $editWamId,
            'edited_at' => $editedAt,
            'original_message_id' => $originalMessageId,
        ];
    }

    protected function decodeChatMetadata($metadata): array
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return is_array($decoded) ? $decoded : [];
    }

    protected function broadcastUpdatedSmbEchoChat(Chat $chat): void
    {
        try {
            $chatLog = ChatLog::where('entity_type', 'chat')
                ->where('entity_id', $chat->id)
                ->first();

            if (! $chatLog) {
                Log::channel('webhook')->warning('SMB edit broadcast skipped - ChatLog not found', [
                    'organization_id' => $this->organizationId,
                    'chat_id' => $chat->id,
                ]);

                return;
            }

            $chat->loadMissing('contact');
            event(new NewChatEvent([[
                'type' => 'chat',
                'value' => $chatLog->relatedEntities,
            ]], $this->organizationId, $chat->contact?->uuid));
        } catch (\Throwable $exception) {
            Log::channel('webhook')->warning('SMB edit broadcast failed (non-blocking)', [
                'organization_id' => $this->organizationId,
                'chat_id' => $chat->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Log SMB message echo failures to a dedicated channel.
     *
     * @param  array  $echo
     * @param  string  $reason
     * @param  \Throwable|null  $exception
     */
    protected function logSmbEchoFailure(array $echo, string $reason, ?\Throwable $exception = null): void
    {
        $context = [
            'organization_id' => $this->organizationId,
            'reason' => $reason,
            'wam_id' => $echo['id'] ?? null,
            'payload' => $echo,
        ];

        if ($exception) {
            $context['exception'] = $exception->getMessage();
            $context['trace'] = $exception->getTraceAsString();
        }

        Log::channel('smb_message_echoes')->error('SMB echo not persisted', $context);
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
        $contact = \App\Models\Contact::withTrashed()->where('organization_id', $this->organizationId)
            ->where(function ($query) use ($formattedPhone, $phone) {
                $query->where('phone', $formattedPhone)
                    ->orWhere('phone', '+' . $phone)
                    ->orWhere('phone', $phone);
            })
            ->first();

        if ($contact && $contact->trashed()) {
            $contact->restore();
        }

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

            case 'add':
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
            'contact_source' => 'whatsapp',
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

        if ($chatType === 'inbound' && (bool) ($contact->is_blocked ?? false)) {
            Log::channel('webhook')->info('History inbound message ignored for blocked contact', [
                'organization_id' => $this->organizationId,
                'contact_id' => $contact->id,
                'wam_id' => $wamId,
            ]);

            return false;
        }

        $contactsSync = $this->syncSharedContactsFromMessage($message);

        // Build metadata (reuse existing method)
        $metadata = $this->buildMessageMetadata($message, $messageType);
        $this->attachContactsSyncMetadata($metadata, $contactsSync);

        // AC-1.12.4: Create chat with original timestamp
        $originalTimestamp = Carbon::createFromTimestamp($timestamp);

        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => $chatType,
            'metadata' => $this->encodeChatMetadata($metadata),
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
            )->onQueue('webhook-media');

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
        )->onQueue('webhook-notifications');

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
        app(TemplateStatusWebhookProcessor::class)->process(
            $this->payload,
            $this->organizationId,
            $this->context
        );
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

        if ($this->webhookType !== 'business_capability_update') {
            return;
        }

        $value = is_array($this->payload['value'] ?? null) ? $this->payload['value'] : [];

        if (! array_key_exists('max_daily_conversations_per_business', $value)) {
            Log::channel('webhook')->warning('Business capability update missing daily conversations tier', [
                'organization_id' => $this->organizationId,
                'payload' => $this->payload,
            ]);

            return;
        }

        $organization = Organization::find($this->organizationId);

        if (! $organization) {
            Log::channel('webhook')->warning('Business capability update organization not found', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $metadata = json_decode($organization->metadata ?: '{}', true);

        if (! is_array($metadata)) {
            $metadata = [];
        }

        if (! isset($metadata['whatsapp']) || ! is_array($metadata['whatsapp'])) {
            $metadata['whatsapp'] = [];
        }

        $oldValue = $metadata['whatsapp']['max_daily_conversations_per_business'] ?? null;
        $oldMessagingLimitTier = $metadata['whatsapp']['messaging_limit_tier'] ?? null;
        $newValue = $value['max_daily_conversations_per_business'];
        $dailyConversationLimitChanged = $oldValue !== $newValue;

        //$metadata['whatsapp']['max_daily_conversations_per_business'] = $newValue;
        $metadata['whatsapp']['whatsapp_business_manager_messaging_limit'] = $newValue;
    
        $metadata['whatsapp']['messaging_limit_tier'] = $newValue;
        $organization->metadata = json_encode($metadata);
        $organization->save();

        Log::channel('webhook')->info('Business capability metadata updated', [
            'organization_id' => $this->organizationId,
            'field' => 'metadata.whatsapp.max_daily_conversations_per_business',
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'messaging_limit_tier_old_value' => $oldMessagingLimitTier,
            'messaging_limit_tier_new_value' => $newValue,
        ]);

        if ($dailyConversationLimitChanged) {
            $this->sendBusinessCapabilityUpdateEmail($organization, $oldValue, $newValue);
        }
    }

    protected function sendBusinessCapabilityUpdateEmail(Organization $organization, mixed $oldValue, mixed $newValue): void
    {
        $recipients = ['rafah@chatberry.net', 'info@chatberry.net'];
        $organizationName = $organization->name ?: 'Unknown organization';

        $message = implode("\n", [
            'This is a notification from Chatberry.',
            '',
            'A WhatsApp business capability webhook updated the daily conversations tier.',
            "Organization ID: {$organization->id}",
            "Organization: {$organizationName}",
            'Field: metadata.whatsapp.max_daily_conversations_per_business',
            'Old value: ' . ($oldValue ?? 'null'),
            'New value: ' . ($newValue ?? 'null'),
            '',
            'Chatberry Notification',
        ]);

        try {
            Mail::raw($message, function ($mail) use ($recipients, $organizationName, $newValue) {
                $mail->to($recipients)
                    ->subject("Chatberry business capability update: {$organizationName} - {$newValue}");
            });

            Log::channel('webhook')->info('Business capability email notification sent', [
                'organization_id' => $organization->id,
                'recipients' => $recipients,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->error('Business capability email notification failed', [
                'organization_id' => $organization->id,
                'recipients' => $recipients,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle an nfm_reply interactive message from a Meta Flow submission.
     *
     * When a user completes a Meta Flow inside WhatsApp, Meta delivers the
     * response as an interactive message with type nfm_reply. This method
     * passes the raw message to MetaFlowService which stores the response.
     *
     * The MetaFlows module may not be installed on every deployment, so the
     * call is wrapped in a class_exists check to avoid hard failures.
     *
     * @param array $message The raw message payload from the webhook
     * @param int   $contactId The local contact ID for the sender
     */
    protected function processMetaFlowResponse(array $message, int $contactId): void
    {
        $interactive = $message['interactive'] ?? [];

        if (($interactive['type'] ?? '') !== 'nfm_reply') {
            return;
        }

        if (!class_exists(MetaFlowService::class)) {
            return;
        }

        try {
            $service = MetaFlowService::fromOrganization($this->organizationId);
            $service->handleFlowResponse($message, $contactId);
        } catch (\Throwable $e) {
            // Non-blocking: a Meta Flow response failure must not interrupt message storage.
            // Log full context so the issue can be diagnosed without a re-deployment.
            Log::channel('webhook')->warning('MetaFlows: nfm_reply handling failed (non-blocking)', [
                'organization_id' => $this->organizationId,
                'contact_id'      => $contactId,
                'exception'       => get_class($e),
                'error'           => $e->getMessage(),
                'file'            => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
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
                $this->recordDeliveredTemplateUsage($chat, $pendingUpdate->status, $payload);

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
            Chat::updateStatusIfProgressed($chat->id, $latestStatus);

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

    protected function recordDeliveredTemplateUsage(Chat $chat, ?string $statusType, array $payload = []): void
    {
        if ($statusType !== 'delivered' || $chat->type !== 'outbound' || ! $chat->wam_id) {
            return;
        }

        $templateContext = $this->templateBillingContext($chat);
        if ($templateContext === null) {
            return;
        }

        (new SubscriptionUsageService())->recordUsage(
            (int) $chat->organization_id,
            SubscriptionUsageService::TEMPLATE_MESSAGES,
            1,
            'template-delivered:' . $chat->wam_id,
            (string) ($templateContext['source'] ?? 'template'),
            is_numeric($templateContext['source_id'] ?? null) ? (int) $templateContext['source_id'] : $chat->id,
            [
                'chat_id' => $chat->id,
                'wam_id' => $chat->wam_id,
                'template_context' => $templateContext,
                'status_payload' => $payload,
            ]
        );
    }

    protected function templateBillingContext(Chat $chat): ?array
    {
        $metadata = json_decode((string) $chat->metadata, true);
        if (! is_array($metadata)) {
            return null;
        }

        $billing = $metadata['billing'] ?? [];
        $billing = is_array($billing) ? $billing : [];

        $isTemplate = $this->truthy($billing['is_template'] ?? null)
            || ($metadata['message_type'] ?? null) === 'template'
            || $this->truthy($metadata['template_message'] ?? null)
            || ($metadata['type'] ?? null) === 'carousel';

        if (! $isTemplate) {
            return null;
        }

        return [
            'is_template' => true,
            'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
            'source' => $billing['source'] ?? 'template',
            'source_id' => $billing['source_id'] ?? $chat->id,
            'count_on_status' => 'delivered',
        ];
    }

    protected function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
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
        $wamId = $this->extractFirstWamId();
        $attempts = $this->attempts();

        Log::channel('webhook')->error('ProcessWebhookJob failed permanently', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'wam_id' => $wamId,
            'queue' => $this->queue,
            'error' => $exception->getMessage(),
            'attempts' => $attempts,
            'trace' => $exception->getTraceAsString(),
        ]);

        // AC-1.5.2: Log to webhook_failures table for monitoring and manual retry
        try {
            $failureData = [
                'organization_id' => $this->organizationId,
                'job_type' => 'ProcessWebhookJob',
                'error_message' => substr($exception->getMessage(), 0, 1000),
                'error_code' => $exception->getCode() ? (string) $exception->getCode() : null,
                'payload' => $this->payload, // Model will cast to JSON
                'attempts' => $attempts,
            ];

            foreach ([
                'wam_id' => $wamId,
                'webhook_type' => $this->webhookType,
                'queue' => $this->queue,
                'sample_id' => $this->context['sample_id'] ?? null,
                'context' => $this->context,
            ] as $column => $value) {
                if (Schema::hasColumn('webhook_failures', $column)) {
                    $failureData[$column] = $value;
                }
            }

            WebhookFailure::create($failureData);
        } catch (\Exception $e) {
            // Fail-safe: don't let logging failure mask original exception
            Log::channel('webhook')->error('Failed to log webhook failure to database', [
                'original_error' => $exception->getMessage(),
                'logging_error' => $e->getMessage(),
            ]);
        }
    }
}
