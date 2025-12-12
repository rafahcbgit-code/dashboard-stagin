<?php

namespace App\Jobs;

use App\Events\CampaignProgressEvent;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Services\OrganizationService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    private $campaignLog;

    private $organizationId;

    private $whatsappService;

    public $timeout = 300; // 5 minutes timeout for single message

    public $tries = 3;

    public function __construct(CampaignLog $campaignLog)
    {
        $this->campaignLog = $campaignLog;
    }

    public function handle()
    {
        try {
            //DB::transaction(function() {
            // Lock the log for update to prevent duplicate processing
            $lockedLog = CampaignLog::where('id', $this->campaignLog->id)
                ->whereIn('status', ['pending', 'failed'])
                ->lockForUpdate()
                ->first();

            if ($lockedLog) {
                // Task 2.6: AC-2.6.4 - Check if campaign was cancelled before processing
                $campaign = Campaign::find($this->campaignLog->campaign_id);

                if (! $campaign || $campaign->status === 'cancelled') {
                    // Campaign cancelled - skip sending and update log status
                    if ($lockedLog->status === 'pending') {
                        $lockedLog->update(['status' => 'cancelled']);
                    }
                    Log::info('Campaign cancelled - skipping message', [
                        'campaign_id' => $campaign?->id,
                        'campaign_log_id' => $this->campaignLog->id,
                    ]);

                    return;
                }

                $campaign_user_id = $campaign->created_by;
                $lockedLog->status = 'ongoing';
                $lockedLog->save();

                $this->organizationId = $this->campaignLog->campaign->organization_id;
                $this->initializeWhatsappService();

                $template = $this->buildTemplateRequest($this->campaignLog->campaign_id, $this->campaignLog->contact);
                $responseObject = $this->whatsappService->sendTemplateMessage(
                    $this->campaignLog->contact->uuid,
                    $template,
                    $campaign_user_id,
                    $this->campaignLog->campaign_id
                );

                $this->updateLogStatus($lockedLog, $responseObject);
            }
            //});
        } catch (\Exception $e) {
            Log::error('Error processing campaign log '.$this->campaignLog->id.': '.$e->getMessage());
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

    protected function checkAndUpdateCampaignStatus($campaignId)
    {
        $campaign = Campaign::find($campaignId);

        // Task 2.2: AC-2.2.2 - Use cached metadata instead of loading via relationship
        $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];

        $pendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($pendingOrOngoing) {
            return; // Still processing logs
        }

        if ($retryEnabled) {
            // Task 2.8: AC-2.8.4 - Use withCount('retries') to avoid N+1 query pattern
            $logs = CampaignLog::where('campaign_id', $campaignId)
                ->withCount('retries')
                ->get();

            foreach ($logs as $log) {
                // Task 2.8: AC-2.8.1 - Use pre-loaded retries_count instead of N+1 query
                $retryCount = $log->retries_count;
                $maxRetries = count($retryIntervals);

                // Skip if already retried max times
                if ($retryCount >= $maxRetries) {
                    continue;
                }

                // If log failed and hasn't reached max retries, schedule retry
                if ($log->status === 'failed' && $retryCount < $maxRetries) {
                    $delayHours = $retryIntervals[$retryCount] ?? null;

                    if ($delayHours !== null) {
                        RetryCampaignLogJob::dispatch($campaign->organization_id, $log->id, $retryCount)
                            ->onQueue('campaign-messages')
                            ->delay(now()->addMinutes($delayHours));
                    }
                }
            }
        } else {
            // Check again after potential changes
            $unprocessed = CampaignLog::where('campaign_id', $campaignId)
                ->whereIn('status', ['pending', 'ongoing'])
                ->exists();

            if (! $unprocessed) {
                $campaign->update(['status' => 'completed']);
            }
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
            Log::warning('Failed to broadcast campaign progress: '.$e->getMessage());
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
        $campaign = Campaign::find($campaignId);
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
        } else {
            unset(self::$progressState[$campaignId]);
        }
    }
}
