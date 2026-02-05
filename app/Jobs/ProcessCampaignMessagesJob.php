<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Services\OrganizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessCampaignMessagesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param  int  $organizationId The organization to process campaigns for
     */
    public function __construct(
        public int $organizationId
    ) {
        $this->queue = 'campaign-messages';
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    /**
     * Static factory method for dispatching per-organization.
     *
     * @param  int  $organizationId The organization ID
     */
    public static function dispatchForOrganization(int $organizationId): PendingDispatch
    {
        return static::dispatch($organizationId);
    }

    public function handle()
    {
        $lock = Cache::lock('campaign_messages_org_'.$this->organizationId, 300);

        if (! $lock->get()) {
            Log::info('Skipping ProcessCampaignMessagesJob due to active lock', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        // Task 2.2: Query count and timing instrumentation (AC-2.2.1, AC-2.2.4)
        $startTime = microtime(true);
        DB::enableQueryLog();
        $initialQueryCount = count(DB::getQueryLog());

        try {
            // Track campaigns to check completion later
            $campaignsProcessed = [];

            // Task 2.2: AC-2.2.2 - Fetch cached metadata once per job (not per log)
            $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
            $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
            $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
            $maxRetries = count($retryIntervals);

            // Task 2.3: AC-2.3.1, AC-2.3.2, AC-2.3.3 - Configurable batch size with org override
            $batchSize = $this->getBatchSize($orgMetadata);
            $batchNumber = 0;
            $totalProcessed = 0;

            // Process logs in chunks - now org-scoped (Task 2.1: AC-2.1.1, AC-2.1.2)
            // Task 2.2: AC-2.2.3 - Selective column loading for reduced data transfer
            // Task 2.8: AC-2.8.3 - Use withCount('retries') to avoid N+1 query pattern
            CampaignLog::select(['id', 'campaign_id', 'contact_id', 'status'])
                ->withCount('retries')
                ->with([
                    'campaign' => fn($q) => $q->select(['id', 'organization_id', 'status', 'uuid', 'created_by']),
                    'contact',
                ])
                ->whereIn('status', ['pending', 'failed'])
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing')
                        ->where('organization_id', $this->organizationId);
                })
                ->chunk($batchSize, function ($logs) use (&$campaignsProcessed, &$batchNumber, &$totalProcessed, $batchSize, $retryEnabled, $maxRetries) {
                    $batchNumber++;
                    // Task 2.3: AC-2.3.4 - Log batch progress at start of each chunk
                    Log::info('ProcessCampaignMessagesJob batch started', [
                        'organization_id' => $this->organizationId,
                        'batch_number' => $batchNumber,
                        'batch_size' => count($logs),
                        'configured_batch_size' => $batchSize,
                    ]);
                    $jobs = [];

                    // Task 2.6: Track campaigns that were cancelled mid-batch
                    $cancelledCampaigns = [];

                    foreach ($logs as $log) {
                        // Task 2.6: AC-2.6.4 - Skip if campaign was cancelled mid-batch
                        if ($log->campaign && $log->campaign->status === 'cancelled') {
                            if (! in_array($log->campaign_id, $cancelledCampaigns)) {
                                $cancelledCampaigns[] = $log->campaign_id;
                                Log::info('Skipping batch dispatch for cancelled campaign', [
                                    'campaign_id' => $log->campaign_id,
                                    'organization_id' => $this->organizationId,
                                ]);
                            }

                            continue;
                        }

                        // Task 2.8: AC-2.8.1 - Use pre-loaded retries_count instead of N+1 query
                        $retryCount = $log->retries_count;

                        // Add job if pending or failed with retries remaining
                        if (
                            $log->status === 'pending' ||
                            ($log->status === 'failed' && $retryEnabled && $retryCount < $maxRetries)
                        ) {
                            $jobs[] = new ProcessSingleCampaignLogJob($log);
                        }

                        // Track campaign IDs
                        if (! in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    // Dispatch jobs to org-specific queue (Task 2.1: AC-2.1.1)
                    if (! empty($jobs)) {
                        Bus::batch($jobs)
                            ->allowFailures()
                            ->onQueue($this->queue)
                            ->dispatch();
                    }

                    // Task 2.3: AC-2.3.4 - Track total processed
                    $totalProcessed += count($logs);
                });

            // Task 2.3: AC-2.3.4 - Log total processed count after all batches complete
            Log::info('ProcessCampaignMessagesJob batches completed', [
                'organization_id' => $this->organizationId,
                'total_batches' => $batchNumber,
                'total_processed' => $totalProcessed,
                'batch_size_used' => $batchSize,
            ]);

            foreach ($campaignsProcessed as $campaignId) {
                $this->markCampaignAsCompleted($campaignId);
            }

            // Task 2.2: Log query metrics for performance baseline (AC-2.2.1, AC-2.2.4)
            $this->logQueryMetrics($startTime, $initialQueryCount, count($campaignsProcessed));
        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: ' . $e->getMessage());
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Log query count and timing metrics for performance analysis.
     * Task 2.2: AC-2.2.1 (query count), AC-2.2.4 (processing time)
     */
    private function logQueryMetrics(float $startTime, int $initialQueryCount, int $campaignsProcessed): void
    {
        $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);
        $queryCount = count(DB::getQueryLog()) - $initialQueryCount;

        Log::info('ProcessCampaignMessagesJob metrics', [
            'organization_id' => $this->organizationId,
            'query_count' => $queryCount,
            'elapsed_ms' => $elapsedTime,
            'campaigns_processed' => $campaignsProcessed,
            'queue' => $this->queue,
        ]);
    }

    /**
     * Get batch size for campaign processing.
     * Task 2.3: AC-2.3.1, AC-2.3.2, AC-2.3.3
     *
     * Priority: org metadata override > config default
     * All values capped at max_batch_size for safety.
     *
     * @param  array  $orgMetadata  Cached organization metadata
     */
    private function getBatchSize(array $orgMetadata): int
    {
        $defaultSize = config('campaigns.batch_size', 500);
        $maxSize = config('campaigns.max_batch_size', 1000);

        // Task 2.3: AC-2.3.3 - Check org metadata for override
        $orgSize = $orgMetadata['campaigns']['batch_size'] ?? null;

        if ($orgSize !== null) {
            return min((int) $orgSize, $maxSize);
        }

        return min($defaultSize, $maxSize);
    }

    private function markCampaignAsCompleted($campaignId)
    {
        $campaign = Campaign::find($campaignId);

        if (! $campaign || $campaign->status !== 'ongoing') {
            return;
        }

        // Task 2.2: AC-2.2.2 - Use cached metadata instead of loading via relationship
        $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        $pendingOrOngoingExists = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($pendingOrOngoingExists) {
            return; // Still processing
        }

        $failedLogsCanRetry = false;

        if ($retryEnabled) {
            // Task 2.8: AC-2.8.3 - Use withCount('retries') to avoid N+1 query pattern
            $failedLogs = CampaignLog::where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->withCount('retries')
                ->get();

            foreach ($failedLogs as $log) {
                // Task 2.8: AC-2.8.1 - Use pre-loaded retries_count instead of N+1 query
                if ($log->retries_count < $maxRetries) {
                    $failedLogsCanRetry = true;
                    break;
                }
            }
        }

        if (! $failedLogsCanRetry) {
            $campaign->status = 'completed';
            $campaign->save();
            Log::info("Campaign {$campaignId} marked as completed.");
        }
    }
}
