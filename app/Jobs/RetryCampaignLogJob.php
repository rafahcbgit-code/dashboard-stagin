<?php

namespace App\Jobs;

use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Services\OrganizationService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryCampaignLogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait;

    public $timeout = 300;

    private $organizationId;

    private $campaignLogId;

    protected $retryIndex;

    public function __construct(int $organizationId, int $campaignLogId, int $retryIndex)
    {
        $this->organizationId = $organizationId;
        $this->campaignLogId = $campaignLogId;
        $this->retryIndex = $retryIndex;
    }

    public function uniqueId()
    {
        return $this->campaignLogId.'-'.$this->retryIndex;
    }

    public function handle()
    {
        $log = CampaignLog::with('campaign', 'campaign.organization', 'contact')->find($this->campaignLogId);

        if (! $log || $log->status !== 'failed') {
            return;
        }

        if ($log->campaign->status == 'completed') {
            return; // Don't process if the campaign has been marked as completed
        }

        // Story 2.4: Use cached metadata with config fallback (AC-2.4.1)
        $metadata = OrganizationService::getCachedMetadata($this->organizationId);
        $campaignSettings = $metadata['campaigns'] ?? [];
        $retryIntervals = $this->getRetryIntervals($campaignSettings);
        $maxRetries = count($retryIntervals);
        $retryCount = $log->retries()->count();

        if ($retryCount >= $maxRetries) {
            return; // Don't process if retry count limit reached
        }

        // Initialize WhatsApp service using cached metadata
        $this->initializeWhatsappService($metadata);

        DB::beginTransaction();

        try {
            // Create retry log
            $retryLog = new CampaignLogRetry();
            $retryLog->campaign_log_id = $this->campaignLogId;
            $retryLog->status = 'ongoing';
            $retryLog->save();

            $template = $this->buildTemplateRequest($log->campaign_id, $log->contact);
            $campaign_user_id = $log->campaign->created_by;

            $response = $this->whatsappService->sendTemplateMessage(
                $log->contact->uuid,
                $template,
                $campaign_user_id,
                $log->campaign_id
            );

            $status = ($response->success === true) ? 'success' : 'failed';
            $retryLog->chat_id = $response->data->chat->id ?? null;
            $retryLog->status = $status;

            // Clean metadata
            unset($response->success);
            if (property_exists($response, 'data') && property_exists($response->data, 'chat')) {
                unset($response->data->chat);
            }

            $retryLog->metadata = json_encode($response);
            $retryLog->save();

            // Update the retry_count on the original campaign log (AC-2.4.2)
            $log->retry_count += 1;
            $log->status = $status;
            $log->save();

            // Check if it failed
            if ($retryLog->status === 'failed') {
                $newRetryCount = $retryCount + 1; // After this retry

                // Story 2.4: Check if max retries reached (AC-2.4.3)
                if ($newRetryCount >= $maxRetries) {
                    $this->handlePermanentFailure($log, $newRetryCount, $maxRetries, $response, $campaignSettings);
                } else {
                    // Schedule next retry
                    $this->scheduleNextRetry($log, $retryIntervals, $maxRetries);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Retry failed for campaign_log {$this->campaignLogId}: ".$e->getMessage());
        }
    }

    /**
     * Get retry intervals with config fallback.
     * Story 2.4: AC-2.4.1 - Use org metadata intervals or fall back to config defaults.
     */
    private function getRetryIntervals(array $campaignSettings): array
    {
        // First try org metadata, then fall back to config
        $intervals = $campaignSettings['resend_intervals'] ?? null;

        if (empty($intervals) || ! is_array($intervals)) {
            $intervals = config('campaigns.retry.default_intervals', [5, 15, 60]);
        }

        // Validate intervals are all positive integers
        return array_values(array_filter($intervals, fn ($val) => is_numeric($val) && $val > 0));
    }

    /**
     * Schedule the next retry attempt.
     * Story 2.4: AC-2.4.1 - Apply exponential backoff using intervals array.
     */
    private function scheduleNextRetry(CampaignLog $log, array $intervals, int $maxRetries): void
    {
        if ($this->retryIndex + 1 < $maxRetries && isset($intervals[$this->retryIndex + 1])) {
            $nextInterval = $intervals[$this->retryIndex + 1];
            // Dispatch to org-specific queue (Story 2.1: AC-2.1.1)
            $orgQueue = config('queue.campaign_prefix', 'campaign-org-').$this->organizationId;
            self::dispatch($this->organizationId, $log->id, $this->retryIndex + 1)
                ->onQueue($orgQueue)
                ->delay(now()->addMinutes($nextInterval));
        }
    }

    /**
     * Handle permanent failure after max retries exhausted.
     * Story 2.4: AC-2.4.3 - Mark as permanently failed, AC-2.4.4 - Log for review.
     */
    private function handlePermanentFailure(
        CampaignLog $log,
        int $retryCount,
        int $maxRetries,
        object $response,
        array $campaignSettings
    ): void {
        // AC-2.4.3: Mark as permanently failed
        $log->status = 'permanently_failed';
        $log->save();

        // AC-2.4.4: Log permanently failed messages with full context
        if (config('campaigns.retry.log_permanent_failures', true)) {
            Log::warning('Campaign message permanently failed', [
                'campaign_log_id' => $log->id,
                'campaign_id' => $log->campaign_id,
                'contact_id' => $log->contact_id,
                'organization_id' => $this->organizationId,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'last_error' => $response->message ?? ($response->error->message ?? 'Unknown error'),
            ]);
        }

        // Move contact to failed group if configured (existing behavior)
        if (! empty($campaignSettings['move_failed_contacts_to_group'])) {
            $this->moveContactToFailedGroup($log->contact_id, $campaignSettings);
        }
    }

    /**
     * Move contact to failed campaign group.
     */
    private function moveContactToFailedGroup(int $contactId, array $campaignSettings): void
    {
        $groupUuid = $campaignSettings['failed_campaign_group'] ?? null;

        if (empty($groupUuid)) {
            return;
        }

        $failedGroupId = DB::table('contact_groups')->where('uuid', $groupUuid)->value('id');

        if (! $failedGroupId) {
            Log::warning('Failed to move contact: Group with UUID '.$groupUuid.' does not exist.');

            return;
        }

        // Remove all groups for the contact
        DB::table('contact_contact_group')
            ->where('contact_id', $contactId)
            ->delete();

        // Add contact to the failed group
        DB::table('contact_contact_group')->insert([
            'contact_id' => $contactId,
            'contact_group_id' => $failedGroupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Initialize WhatsApp service using cached organization metadata.
     * Story 2.4: Fixed bug where metadata was cached but then immediately overwritten.
     *
     * @param  array  $metadata  Cached organization metadata from OrganizationService::getCachedMetadata()
     */
    private function initializeWhatsappService(array $metadata): void
    {
        $accessToken = $metadata['whatsapp']['access_token'] ?? null;
        $apiVersion = 'v18.0';
        $appId = $metadata['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $metadata['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $metadata['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService(
            $accessToken,
            $apiVersion,
            $appId,
            $phoneNumberId,
            $wabaId,
            $this->organizationId
        );
    }
}
