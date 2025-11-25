<?php

namespace App\Jobs\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessWebhookJob - Async webhook processing job.
 *
 * This job handles all webhook processing in the background, allowing the
 * WebhookController to return HTTP 200 immediately (Immediate 200 OK Pattern).
 *
 * @see Story 1.2 for full implementation of handle() method
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
     * Execute the job.
     *
     * Note: Full implementation will be added in Story 1.2.
     * This skeleton validates the job runs but defers processing.
     */
    public function handle(): void
    {
        Log::channel('webhook')->info('ProcessWebhookJob received', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'job_id' => $this->job?->getJobId(),
        ]);

        // TODO: Story 1.2 - Implement full webhook processing logic
        // This will include:
        // - Message processing (contacts, chats, media)
        // - Status updates
        // - SMB echoes and state sync
        // - History sync
        // - Template and account status updates
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('ProcessWebhookJob failed', [
            'organization_id' => $this->organizationId,
            'webhook_type' => $this->webhookType,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
