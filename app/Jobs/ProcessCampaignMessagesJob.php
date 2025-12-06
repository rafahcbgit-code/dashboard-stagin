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
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCampaignMessagesJob implements ShouldQueue
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
        $this->queue = (config('queue.campaign_prefix') ?? 'campaign-org-').$this->organizationId;
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
        // Story 2.2: Query count and timing instrumentation (AC-2.2.1, AC-2.2.4)
        $startTime = microtime(true);
        DB::enableQueryLog();
        $initialQueryCount = count(DB::getQueryLog());

        try {
            // Track campaigns to check completion later
            $campaignsProcessed = [];

            // Story 2.2: AC-2.2.2 - Fetch cached metadata once per job (not per log)
            $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
            $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
            $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
            $maxRetries = count($retryIntervals);

            // Process logs in chunks - now org-scoped (Story 2.1: AC-2.1.1, AC-2.1.2)
            // Story 2.2: AC-2.2.3 - Selective column loading for reduced data transfer
            CampaignLog::select(['id', 'campaign_id', 'contact_id', 'status'])
                ->with([
                    'campaign' => fn ($q) => $q->select(['id', 'organization_id', 'status', 'uuid', 'created_by']),
                    'contact',
                ])
                ->whereIn('status', ['pending', 'failed'])
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing')
                        ->where('organization_id', $this->organizationId);
                })
                ->chunk(500, function ($logs) use (&$campaignsProcessed, $retryEnabled, $maxRetries) {
                    $jobs = [];

                    foreach ($logs as $log) {
                        // Get actual retry count from database relationship
                        $retryCount = $log->retries()->count();

                        // Add job if pending or failed with retries remaining
                        if ($log->status === 'pending' ||
                            ($log->status === 'failed' && $retryEnabled && $retryCount < $maxRetries)) {
                            $jobs[] = new ProcessSingleCampaignLogJob($log);
                        }

                        // Track campaign IDs
                        if (! in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    // Dispatch jobs to org-specific queue (Story 2.1: AC-2.1.1)
                    if (! empty($jobs)) {
                        Bus::batch($jobs)
                            ->allowFailures()
                            ->onQueue($this->queue)
                            ->dispatch();
                    }
                });

            foreach ($campaignsProcessed as $campaignId) {
                $this->markCampaignAsCompleted($campaignId);
            }

            // Story 2.2: Log query metrics for performance baseline (AC-2.2.1, AC-2.2.4)
            $this->logQueryMetrics($startTime, $initialQueryCount, count($campaignsProcessed));

        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Log query count and timing metrics for performance analysis.
     * Story 2.2: AC-2.2.1 (query count), AC-2.2.4 (processing time)
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

    private function markCampaignAsCompleted($campaignId)
    {
        $campaign = Campaign::find($campaignId);

        if (! $campaign || $campaign->status !== 'ongoing') {
            return;
        }

        // Story 2.2: AC-2.2.2 - Use cached metadata instead of loading via relationship
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
            $failedLogs = CampaignLog::where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->get();

            foreach ($failedLogs as $log) {
                $retryCount = $log->retries()->count();
                if ($retryCount < $maxRetries) {
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
