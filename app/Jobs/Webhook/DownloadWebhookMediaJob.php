<?php

namespace App\Jobs\Webhook;

use App\Models\Chat;
use App\Models\ChatMedia;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\WebhookFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DownloadWebhookMediaJob - Downloads media from WhatsApp API asynchronously.
 *
 * This job handles async download of media files (images, videos, documents, audio, sticker)
 * from WhatsApp's CDN and stores them in the configured storage system.
 *
 * Flow:
 * 1. Get media URL from WhatsApp Graph API using media_id
 * 2. Download media content with Authorization header
 * 3. Store in configured storage (local/S3) based on org settings
 * 4. Create ChatMedia record with file metadata
 * 5. Update Chat record with media_id reference
 *
 * Dispatched by: ProcessWebhookJob when message contains media
 * Queue: webhook-high
 *
 * @see Story 1.3 - Create DownloadWebhookMediaJob for Async Media Handling
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
     * Expected structure: ['type', 'media_id', 'mime_type', 'sha256', 'filename', 'caption']
     */
    public array $mediaData;

    /**
     * The number of times the job may be attempted (AC-1.3.5).
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     * 5 minutes allows for large video/document files.
     */
    public int $timeout = 300;

    /**
     * Exponential backoff intervals in seconds (AC-1.3.5).
     * [1 minute, 5 minutes, 15 minutes]
     */
    public array $backoff = [60, 300, 900];

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
     * Downloads media from WhatsApp, stores it, and updates database records.
     * Implements AC-1.3.1 through AC-1.3.4 and AC-1.3.7.
     */
    public function handle(): void
    {
        $totalStartTime = microtime(true);

        $mediaId = $this->mediaData['media_id'] ?? null;
        $mediaType = $this->mediaData['type'] ?? 'unknown';

        Log::channel('webhook')->info('DownloadWebhookMediaJob started', [
            'chat_id' => $this->chatId,
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'attempt' => $this->attempts(),
        ]);

        if (! $mediaId) {
            Log::channel('webhook')->warning('DownloadWebhookMediaJob: No media_id provided', [
                'chat_id' => $this->chatId,
                'media_data' => $this->mediaData,
            ]);

            return;
        }

        // Step 1: Get organization and access token (AC-1.3.1)
        $organization = Organization::find($this->organizationId);
        if (! $organization) {
            throw new \RuntimeException("Organization not found: {$this->organizationId}");
        }

        $metadata = json_decode($organization->metadata);
        $accessToken = $metadata->whatsapp->access_token ?? null;

        if (! $accessToken) {
            throw new \RuntimeException("WhatsApp access token not configured for organization: {$this->organizationId}");
        }

        // Step 2: Get media URL from WhatsApp Graph API (AC-1.3.1)
        $apiStartTime = microtime(true);
        $mediaInfo = $this->getMediaUrlFromWhatsApp($mediaId, $accessToken);
        $apiDuration = (microtime(true) - $apiStartTime) * 1000;

        Log::channel('webhook')->info('WhatsApp API response time', [
            'chat_id' => $this->chatId,
            'media_id' => $mediaId,
            'duration_ms' => round($apiDuration, 2),
        ]);

        // Step 3: Download media content (AC-1.3.1)
        $downloadStartTime = microtime(true);
        $fileContent = $this->downloadMediaContent($mediaInfo['url'], $accessToken);
        $downloadDuration = (microtime(true) - $downloadStartTime) * 1000;

        Log::channel('webhook')->info('Media download completed', [
            'chat_id' => $this->chatId,
            'media_id' => $mediaId,
            'duration_ms' => round($downloadDuration, 2),
            'size_bytes' => strlen($fileContent),
        ]);

        // Step 4: Store in configured storage (AC-1.3.2)
        $uploadStartTime = microtime(true);
        $mimeType = $mediaInfo['mime_type'] ?? $this->mediaData['mime_type'] ?? 'application/octet-stream';
        $storageResult = $this->storeMedia($fileContent, $mimeType, $organization);
        $uploadDuration = (microtime(true) - $uploadStartTime) * 1000;

        Log::channel('webhook')->info('Media upload completed', [
            'chat_id' => $this->chatId,
            'media_id' => $mediaId,
            'duration_ms' => round($uploadDuration, 2),
            'storage' => $storageResult['location'],
            'path' => $storageResult['path'],
        ]);

        // Step 5: Create ChatMedia record (AC-1.3.3)
        $chatMedia = $this->createChatMediaRecord($storageResult, $mimeType, strlen($fileContent));

        // Step 6: Update Chat record with media reference (AC-1.3.4)
        $this->updateChatRecord($chatMedia);

        // Log total processing time (AC-1.3.7)
        $totalDuration = (microtime(true) - $totalStartTime) * 1000;

        Log::channel('webhook')->info('DownloadWebhookMediaJob completed', [
            'chat_id' => $this->chatId,
            'media_id' => $mediaId,
            'chat_media_id' => $chatMedia->id,
            'total_duration_ms' => round($totalDuration, 2),
            'api_duration_ms' => round($apiDuration, 2),
            'download_duration_ms' => round($downloadDuration, 2),
            'upload_duration_ms' => round($uploadDuration, 2),
            'storage_location' => $storageResult['location'],
        ]);
    }

    /**
     * Get media URL from WhatsApp Graph API.
     *
     * @param  string  $mediaId WhatsApp media ID
     * @param  string  $accessToken WhatsApp access token
     * @return array Media info with 'url' and 'mime_type'
     *
     * @throws \RuntimeException If API call fails
     */
    protected function getMediaUrlFromWhatsApp(string $mediaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(120)
            ->get("https://graph.facebook.com/v18.0/{$mediaId}");

        if ($response->failed()) {
            throw new \RuntimeException("Failed to get media URL from WhatsApp: {$response->body()}");
        }

        $data = $response->json();

        if (empty($data['url'])) {
            throw new \RuntimeException("WhatsApp API returned no URL for media: {$mediaId}");
        }

        return $data;
    }

    /**
     * Download media content from WhatsApp CDN.
     *
     * @param  string  $url Media download URL
     * @param  string  $accessToken WhatsApp access token
     * @return string Binary file content
     *
     * @throws \RuntimeException If download fails
     */
    protected function downloadMediaContent(string $url, string $accessToken): string
    {
        $response = Http::withToken($accessToken)
            ->timeout(120)
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException("Failed to download media content: {$response->body()}");
        }

        return $response->body();
    }

    /**
     * Store media to configured storage system (local or S3).
     *
     * @param  string  $fileContent Binary file content
     * @param  string  $mimeType File MIME type
     * @param  Organization  $organization Organization for storage config
     * @return array ['path' => string, 'url' => string, 'location' => 'local'|'amazon']
     */
    protected function storeMedia(string $fileContent, string $mimeType, Organization $organization): array
    {
        // Generate unique filename
        $fileName = $this->generateFilename($fileContent, $mimeType);

        // Determine storage type: check org metadata first, then system setting
        $metadata = json_decode($organization->metadata);
        $storageType = $metadata->storage ?? null;

        if (! $storageType) {
            $storageType = Setting::where('key', 'storage_system')->value('value') ?? 'local';
        }

        if ($storageType === 'aws' || $storageType === 's3') {
            return $this->storeToS3($fileContent, $fileName, $mimeType, $organization);
        }

        return $this->storeToLocal($fileContent, $fileName);
    }

    /**
     * Store media to local filesystem.
     *
     * @param  string  $fileContent Binary file content
     * @param  string  $fileName Generated filename
     * @return array Storage result
     */
    protected function storeToLocal(string $fileContent, string $fileName): array
    {
        $path = 'public/'.$fileName;
        Storage::disk('local')->put($path, $fileContent);

        $url = rtrim(config('app.url'), '/').'/media/'.$path;

        return [
            'path' => $path,
            'url' => $url,
            'location' => 'local',
        ];
    }

    /**
     * Store media to S3.
     *
     * @param  string  $fileContent Binary file content
     * @param  string  $fileName Generated filename
     * @param  string  $mimeType File MIME type
     * @param  Organization  $organization Organization for path namespacing
     * @return array Storage result
     */
    protected function storeToS3(string $fileContent, string $fileName, string $mimeType, Organization $organization): array
    {
        // Use organization ID for namespacing, include the generated filename
        $path = 'uploads/media/received/'.$organization->id.'/'.$fileName;

        Storage::disk('s3')->put($path, $fileContent, [
            'ContentType' => $mimeType,
        ]);

        $url = Storage::disk('s3')->url($path);

        return [
            'path' => $path,
            'url' => $url,
            'location' => 'amazon',
        ];
    }

    /**
     * Generate unique filename based on content hash and MIME type.
     *
     * @param  string  $fileContent Binary file content
     * @param  string  $mimeType File MIME type
     * @return string Generated filename
     */
    protected function generateFilename(string $fileContent, string $mimeType): string
    {
        $hash = sha1($fileContent);
        $extension = $this->getExtensionFromMimeType($mimeType);

        return "{$hash}_".time().".{$extension}";
    }

    /**
     * Get file extension from MIME type.
     *
     * @param  string  $mimeType MIME type
     * @return string File extension
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/aac' => 'aac',
            'audio/amr' => 'amr',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
        ];

        if (isset($mimeToExt[$mimeType])) {
            return $mimeToExt[$mimeType];
        }

        // Fallback: extract from mime type
        $parts = explode('/', $mimeType);

        return $parts[1] ?? 'bin';
    }

    /**
     * Create ChatMedia record with file metadata (AC-1.3.3).
     *
     * @param  array  $storageResult Storage operation result
     * @param  string  $mimeType File MIME type
     * @param  int  $fileSize File size in bytes
     * @return ChatMedia Created record
     */
    protected function createChatMediaRecord(array $storageResult, string $mimeType, int $fileSize): ChatMedia
    {
        $mediaType = $this->mediaData['type'] ?? 'unknown';
        $originalFilename = $this->mediaData['filename'] ?? null;

        // Generate display name
        $displayName = $originalFilename ?: $this->generateDisplayName($mediaType, $mimeType);

        return ChatMedia::create([
            'name' => $displayName,
            'path' => $storageResult['url'], // Store full URL for easy access
            'location' => $storageResult['location'],
            'type' => $mediaType,
            'size' => $this->formatFileSize($fileSize),
            'created_at' => now(),
        ]);
    }

    /**
     * Generate display name for media without original filename.
     *
     * @param  string  $mediaType Media type (image, video, etc.)
     * @param  string  $mimeType MIME type
     * @return string Display name
     */
    protected function generateDisplayName(string $mediaType, string $mimeType): string
    {
        $extension = $this->getExtensionFromMimeType($mimeType);
        $timestamp = now()->format('Y-m-d_H-i-s');

        return ucfirst($mediaType)."_{$timestamp}.{$extension}";
    }

    /**
     * Format file size for human-readable display.
     *
     * @param  int  $bytes File size in bytes
     * @return string Formatted size
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Update Chat record with media reference (AC-1.3.4).
     *
     * @param  ChatMedia  $chatMedia Created media record
     */
    protected function updateChatRecord(ChatMedia $chatMedia): void
    {
        $chat = Chat::find($this->chatId);

        if ($chat) {
            $chat->update([
                'media_id' => $chatMedia->id,
            ]);
            
            // Broadcast an updated chat event to trigger UI refresh for media
            $chat->load(['contact', 'media']);
            $chatLog = \App\Models\ChatLog::where('entity_type', 'chat')
                ->where('entity_id', $chat->id)
                ->first();

            if ($chatLog) {
                $chatArray = [[
                    'type' => 'chat',
                    'value' => $chatLog->relatedEntities,
                ]];
                event(new \App\Events\NewChatEvent($chatArray, $this->organizationId, $chat->contact->uuid));
            }

            Log::channel('webhook')->info('Chat record updated with media', [
                'chat_id' => $this->chatId,
                'media_id' => $chatMedia->id,
            ]);
        } else {
            Log::channel('webhook')->warning('Chat not found for media update', [
                'chat_id' => $this->chatId,
                'chat_media_id' => $chatMedia->id,
            ]);
        }
    }

    /**
     * Handle a job failure after all retries exhausted (AC-1.3.6).
     *
     * Logs the failure to webhook_failures table for monitoring.
     *
     * @param  \Throwable  $exception The exception that caused the failure
     */
    public function failed(\Throwable $exception): void
    {
        $mediaId = $this->mediaData['media_id'] ?? null;
        $mediaType = $this->mediaData['type'] ?? null;

        Log::channel('webhook')->error('DownloadWebhookMediaJob failed permanently', [
            'chat_id' => $this->chatId,
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
            'media_id' => $mediaId,
            'media_type' => $mediaType,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Log to webhook_failures table (AC-1.3.6)
        try {
            WebhookFailure::create([
                'organization_id' => $this->organizationId,
                'job_type' => 'DownloadWebhookMediaJob',
                'chat_id' => $this->chatId,
                'contact_id' => $this->contactId,
                'media_id' => $mediaId,
                'media_type' => $mediaType,
                'error_message' => $exception->getMessage(),
                'error_code' => $exception->getCode() ?: null,
                'payload' => json_encode($this->mediaData),
                'attempts' => $this->attempts(),
            ]);
        } catch (\Exception $e) {
            Log::channel('webhook')->error('Failed to log webhook failure to database', [
                'original_error' => $exception->getMessage(),
                'logging_error' => $e->getMessage(),
            ]);
        }
    }
}
