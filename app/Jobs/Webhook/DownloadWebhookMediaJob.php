<?php

namespace App\Jobs\Webhook;

use App\Models\Chat;
use App\Models\ChatMedia;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DownloadWebhookMediaJob - Downloads media from WhatsApp API.
 *
 * This job handles async download of media files (images, videos, documents, audio)
 * from WhatsApp's CDN and stores them in the configured storage system.
 *
 * Dispatched by: ProcessWebhookJob when message contains media
 * Queue: webhook-high
 *
 * @see Story 1.3 for full implementation
 */
class DownloadWebhookMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The chat ID to update with media.
     */
    public int $chatId;

    /**
     * The contact ID for file organization.
     */
    public int $contactId;

    /**
     * The organization ID for scoping and credentials.
     */
    public int $organizationId;

    /**
     * The media data from webhook payload.
     */
    public array $mediaData;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes for large files

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(int $chatId, int $contactId, int $organizationId, array $mediaData)
    {
        $this->chatId = $chatId;
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
        $this->mediaData = $mediaData;
        $this->onQueue('webhook-high');
    }

    /**
     * Execute the job.
     *
     * Full implementation in Story 1.3:
     * - Get media URL from WhatsApp Graph API using media_id
     * - Download media content
     * - Store in configured storage (local/S3)
     * - Update Chat record with media_id
     */
    public function handle(): void
    {
        Log::channel('webhook')->info('DownloadWebhookMediaJob started', [
            'chat_id' => $this->chatId,
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
            'media_type' => $this->mediaData['type'] ?? 'unknown',
            'media_id' => $this->mediaData['media_id'] ?? null,
        ]);

        // TODO: Story 1.3 - Full implementation
        // 1. Get organization's WhatsApp access token
        // 2. Call Graph API to get media URL: GET https://graph.facebook.com/v18.0/{media_id}
        // 3. Download media from URL with Authorization header
        // 4. Store in local/S3 storage
        // 5. Create ChatMedia record
        // 6. Update Chat record with media_id

        // Placeholder: Log that media download is pending
        Log::channel('webhook')->info('DownloadWebhookMediaJob placeholder - Story 1.3', [
            'chat_id' => $this->chatId,
            'media_data' => $this->mediaData,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('DownloadWebhookMediaJob failed', [
            'chat_id' => $this->chatId,
            'organization_id' => $this->organizationId,
            'media_id' => $this->mediaData['media_id'] ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
