<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Template;
use App\Models\CampaignLog;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\CampaignProgressEvent;
use App\Services\OrganizationService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessSingleCampaignLogJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait;

    /**
     * Task 2.5: Static progress tracking state shared across job instances.
     * Tracks last broadcast timestamp and message count per campaign.
     *
     * @var array<int, array{last_broadcast: int, messages_since: int}>
     */
    protected static array $progressState = [];

    protected static array $campaignCache = [];
    protected static array $templateCache = [];

    public int $campaignLogId;
    public int $organizationId;
    public int $campaignId;
    public int $createdBy;
    public int $templateId;
    public int $contactId;
    public string $contactUuid;

    private $whatsappService;

    public $timeout = 300; // 5 minutes timeout for single message

    public $tries = 3;

    public function __construct(
        int $campaignLogId,
        int $organizationId,
        int $campaignId,
        int $createdBy,
        int $templateId,
        int $contactId,
        string $contactUuid
    ) {
        $this->queue = config('campaigns.queues.messages', 'campaign-messages');
        $this->campaignLogId = $campaignLogId;
        $this->organizationId = $organizationId;
        $this->campaignId = $campaignId;
        $this->createdBy = $createdBy;
        $this->templateId = $templateId;
        $this->contactId = $contactId;
        $this->contactUuid = $contactUuid;
    }

    public function handle()
    {
        try {
            $lockedLog = DB::transaction(function () {
                // Lock the log for update to prevent duplicate processing
                return CampaignLog::where('id', $this->campaignLogId)
                    ->whereIn('status', ['pending', 'failed'])
                    ->lockForUpdate()
                    ->first();
            });

            if ($lockedLog) {
                // Task 2.6: AC-2.6.4 - Check if campaign was cancelled before processing
                $campaign = $this->getCampaignCached();

                if (! $campaign || $campaign->status === 'cancelled') {
                    // Campaign cancelled - skip sending and update log status
                    if ($lockedLog->status === 'pending') {
                        $lockedLog->update(['status' => 'cancelled']);
                    }
                    Log::info('Campaign cancelled - skipping message', [
                        'campaign_id' => $campaign?->id,
                        'campaign_log_id' => $this->campaignLogId,
                    ]);

                    return;
                }

                $lockedLog->status = 'ongoing';
                $lockedLog->save();

                $this->initializeWhatsappService();

                try {
                    $jobStartTime = microtime(true);

                    $contact = Contact::find($this->contactId);
                    if (!$contact) {
                        $this->markLogFailed($lockedLog, 'Contact not found.', 'CAMPAIGN_CONTACT_NOT_FOUND', [
                            'contact_id' => $this->contactId,
                        ]);
                        return;
                    }

                    // Detect if this is a carousel template (reuse cached template)
                    $template = $this->getTemplateCached();
                    $metadata = json_decode($template->metadata, true);
                    $isCarousel = ($metadata['template_type'] ?? '') === 'carousel';

                    if ($isCarousel) {
                        // Build carousel template payload
                        $buildStart = microtime(true);
                        $templatePayload = $this->buildCarouselTemplateRequest(
                            $template->id,
                            $contact,
                            $template
                        );
                        $buildDuration = round((microtime(true) - $buildStart) * 1000, 2);

                        // Enrich with fresh media IDs (cached per campaign)
                        $enrichStart = microtime(true);
                        $templatePayload = $this->whatsappService->enrichCarouselWithMediaIds(
                            $templatePayload,
                            $this->campaignId
                        );
                        $enrichDuration = round((microtime(true) - $enrichStart) * 1000, 2);

                        Log::channel('campaign')->info('Carousel payload prepared', [
                            'campaign_log_id' => $this->campaignLogId,
                            'campaign_id' => $this->campaignId,
                            'build_ms' => $buildDuration,
                            'media_enrich_ms' => $enrichDuration,
                            'card_count' => count($metadata['carousel_cards'] ?? []),
                        ]);
                    } else {
                        // Build regular template payload
                        $templatePayload = $this->buildTemplateRequest(
                            $this->campaignId,
                            $contact,
                            $campaign,
                            $template
                        );

                        $validationError = $this->validateResolvedTemplate($templatePayload);
                        if ($validationError) {
                            $responseObject = (object) [
                                'success' => false,
                                'message' => $validationError,
                                'data' => (object) [],
                            ];

                            $this->updateLogStatus($lockedLog, $responseObject);
                            return;
                        }
                    }

                    // Send to Meta API
                    $sendStart = microtime(true);
                    $responseObject = $this->whatsappService->sendTemplateMessage(
                        $this->contactUuid,
                        $templatePayload,
                        $this->createdBy,
                        $this->campaignId,
                        null,
                        $contact,
                        $campaign,
                        $template
                    );
                    $sendDuration = round((microtime(true) - $sendStart) * 1000, 2);

                    // Update DB log status
                    $dbStart = microtime(true);
                    $this->updateLogStatus($lockedLog, $responseObject);
                    $dbDuration = round((microtime(true) - $dbStart) * 1000, 2);

                    $totalDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

                    Log::channel('campaign')->info('Campaign message processed', [
                        'campaign_log_id' => $this->campaignLogId,
                        'campaign_id' => $this->campaignId,
                        'is_carousel' => $isCarousel,
                        'meta_api_ms' => $sendDuration,
                        'db_update_ms' => $dbDuration,
                        'total_ms' => $totalDuration,
                        'success' => $responseObject->success ?? false,
                    ]);
                } catch (\Exception $e) {
                    // Reset status to failed so the log can be retried, preventing stuck "ongoing" state
                    $this->markLogFailed($lockedLog, $e->getMessage(), 'CAMPAIGN_SINGLE_LOG_FAILED', [
                        'exception' => get_class($e),
                        'contact_id' => $this->contactId,
                        'template_id' => $this->templateId,
                    ]);
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error processing campaign log ' . $this->campaignLogId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    protected function updateLogStatus(CampaignLog $log, $responseObject)
    {
        $log->chat_id = $responseObject->data->chat->id ?? null;
        $log->status = ($responseObject->success === true) ? 'success' : 'failed';

        // Clean up response object
        unset($responseObject->success);
        if (property_exists($responseObject, 'data') && property_exists($responseObject->data, 'chat')) {
            unset($responseObject->data->chat);
        }

        $log->metadata = json_encode($responseObject);
        $log->updated_at = now();
        $log->save();

        // Task 2.5: Broadcast progress update with throttling
        $this->broadcastProgressIfNeeded($log->campaign_id);

        // Check if campaign is completed
        $this->checkAndUpdateCampaignStatus($log->campaign_id);
    }

    private function markLogFailed(CampaignLog $log, string $message, string $code, array $context = []): void
    {
        $log->update([
            'status' => 'failed',
            'metadata' => json_encode(array_filter([
                'success' => false,
                'message' => $message,
                'code' => $code,
                'context' => $context ?: null,
            ], static fn ($value) => $value !== null)),
            'updated_at' => now(),
        ]);
    }

    protected function checkAndUpdateCampaignStatus($campaignId)
    {
        $campaign = $this->getCampaignCached();

        if (! $campaign || $campaign->status !== 'ongoing') {
            return;
        }

        // Use cached metadata instead of loading via relationship
        $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        $pendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($pendingOrOngoing) {
            return; // Still processing logs
        }

        // Track if any failed logs can still be retried
        $hasRetriableLogs = false;

        if ($retryEnabled) {
            // Use withCount('retries') to avoid N+1 query pattern
            $failedLogs = CampaignLog::where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->withCount('retries')
                ->get();

            foreach ($failedLogs as $log) {
                $retryCount = $log->retries_count;

                // Skip if already retried max times
                if ($retryCount >= $maxRetries) {
                    continue;
                }

                // This log can still be retried
                $hasRetriableLogs = true;

                // Schedule retry job
                $delayMinutes = $retryIntervals[$retryCount] ?? null;

                if ($delayMinutes !== null) {
                    RetryCampaignLogJob::dispatch($campaign->organization_id, $log->id, $retryCount)
                        ->onQueue(config('campaigns.queues.messages', 'campaign-messages'))
                        ->delay(now()->addMinutes($delayMinutes));
                }
            }
        }

        // Mark campaign as completed if no pending/ongoing logs AND no retriable failed logs
        if (! $hasRetriableLogs) {
            $campaign->update([
                'status' => 'completed',
                'completed_at' => $campaign->completed_at ?? now(),
            ]);
            Log::info("Campaign {$campaignId} marked as completed.");
        }
    }

    private function initializeWhatsappService()
    {
        // Task 2.2: AC-2.2.2 - Use centralized cached metadata (fixes redundant fetch bug)
        $config = OrganizationService::getCachedMetadata($this->organizationId);

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = 'v18.0';
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService(
            $accessToken,
            $apiVersion,
            $appId,
            $phoneNumberId,
            $wabaId,
            $this->organizationId
        );
    }

    private function getCampaignCached(): ?Campaign
    {
        if (!isset(self::$campaignCache[$this->campaignId])) {
            self::$campaignCache[$this->campaignId] = Campaign::find($this->campaignId);
        }
        return self::$campaignCache[$this->campaignId];
    }

    private function getTemplateCached(): ?Template
    {
        if (!isset(self::$templateCache[$this->templateId])) {
            self::$templateCache[$this->templateId] = Template::find($this->templateId);
        }
        return self::$templateCache[$this->templateId];
    }

    /**
     * Task 2.5: AC-2.5.3 - Check if progress should be broadcast based on throttle rules.
     * Broadcasts if 10 messages processed OR 5 seconds elapsed since last broadcast.
     */
    protected function shouldBroadcastProgress(int $campaignId): bool
    {
        $state = self::$progressState[$campaignId] ?? [
            'last_broadcast' => 0,
            'messages_since' => 0,
        ];

        $state['messages_since']++;
        $now = time();

        // AC-2.5.3: Broadcast if 10 messages OR 5 seconds elapsed
        if ($state['messages_since'] >= 10 || ($now - $state['last_broadcast']) >= 5) {
            self::$progressState[$campaignId] = [
                'last_broadcast' => $now,
                'messages_since' => 0,
            ];

            return true;
        }

        self::$progressState[$campaignId] = $state;

        return false;
    }

    /**
     * Task 2.5: Orchestrate progress broadcasting with throttle check.
     * Always broadcasts on campaign completion (100%).
     */
    protected function broadcastProgressIfNeeded(int $campaignId): void
    {
        try {
            $stats = $this->getCampaignStats($campaignId);

            // Calculate if campaign is complete
            $isComplete = $stats->pending === 0 && $stats->ongoing === 0;

            // AC-2.5.3: Always broadcast on completion, otherwise apply throttle
            if ($isComplete || $this->shouldBroadcastProgress($campaignId)) {
                $this->broadcastProgress($campaignId, $stats);
            }
        } catch (\Exception $e) {
            // Log but don't fail the job due to broadcast issues
            Log::warning('Failed to broadcast campaign progress: ' . $e->getMessage());
        }
    }

    /**
     * Task 2.5: AC-2.5.2 - Get campaign stats for progress calculation.
     */
    protected function getCampaignStats(int $campaignId): object
    {
        return CampaignLog::where('campaign_id', $campaignId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing
            ")
            ->first();
    }

    /**
     * Task 2.5: AC-2.5.1, AC-2.5.2 - Dispatch CampaignProgressEvent with calculated stats.
     */
    protected function broadcastProgress(int $campaignId, object $stats): void
    {
        $campaign = $this->getCampaignCached();
        if (! $campaign) {
            return;
        }

        // AC-2.5.2: Calculate percentComplete
        $percentComplete = $stats->total > 0
            ? round(($stats->sent + $stats->failed) / $stats->total * 100, 1)
            : 0;

        // AC-2.5.5: Calculate estimated completion time
        $estimatedCompletion = $this->calculateEstimatedCompletion(
            $campaign,
            (int) $stats->sent,
            (int) $stats->pending
        );

        // AC-2.5.1: Dispatch to private-campaign.{uuid} channel
        event(new CampaignProgressEvent(
            campaignUuid: $campaign->uuid,
            total: (int) $stats->total,
            sent: (int) $stats->sent,
            failed: (int) $stats->failed,
            pending: (int) $stats->pending,
            percentComplete: $percentComplete,
            estimatedCompletion: $estimatedCompletion
        ));
    }

    /**
     * Task 2.5: AC-2.5.5 - Calculate estimated completion time based on send rate.
     */
    protected function calculateEstimatedCompletion(Campaign $campaign, int $sent, int $pending): ?string
    {
        if ($sent === 0 || $pending === 0) {
            return $pending === 0 ? null : 'Calculating...';
        }

        // Calculate rate based on campaign start
        // Use created_at as proxy for when campaign started (Campaign model has timestamps = false)
        $startTime = $campaign->created_at;
        if (is_string($startTime)) {
            $startTime = \Carbon\Carbon::parse($startTime);
        }
        $elapsedSeconds = now()->diffInSeconds($startTime);

        if ($elapsedSeconds < 10) {
            return 'Calculating...';
        }

        $rate = $sent / $elapsedSeconds; // messages per second
        if ($rate <= 0) {
            return 'Calculating...';
        }

        $remainingSeconds = $pending / $rate;

        if ($remainingSeconds < 60) {
            return 'Less than a minute remaining';
        } elseif ($remainingSeconds < 3600) {
            $minutes = (int) ceil($remainingSeconds / 60);

            return "~{$minutes} minutes remaining";
        } else {
            $hours = round($remainingSeconds / 3600, 1);

            return "~{$hours} hours remaining";
        }
    }

    /**
     * Task 2.5: Reset progress state for a campaign (useful for testing).
     */
    public static function resetProgressState(int $campaignId = null): void
    {
        if ($campaignId === null) {
            self::$progressState = [];
            self::$campaignCache = [];
            self::$templateCache = [];
        } else {
            unset(self::$progressState[$campaignId]);
            unset(self::$campaignCache[$campaignId]);
        }
    }
}
