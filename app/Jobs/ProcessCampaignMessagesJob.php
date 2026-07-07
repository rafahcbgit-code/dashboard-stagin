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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        $this->queue = config('campaigns.queues.dispatchers', 'campaign-dispatchers');
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
        $cache = Cache::store('redis');
        $lock = $cache->lock(
            'campaign_dispatch_org_'.$this->organizationId,
            (int) config('campaigns.dispatch.lock_seconds', 30)
        );

        if (! $lock->get()) {
            Log::info('Skipping campaign dispatcher due to active lock', [
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $startTime = microtime(true);

        try {
            $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
            $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
            $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
            $maxRetries = count($retryIntervals);

            $chunkSize = $this->getChunkSize($orgMetadata);
            $concurrency = $this->getConcurrency($orgMetadata);
            $requestDelayMs = $this->getRequestDelayMs($orgMetadata);
            $waveSize = max(1, (int) config('campaigns.dispatch.wave_size', 2100));
            $perCampaignWaveSize = max(1, (int) config('campaigns.dispatch.per_campaign_wave_size', 700));
            $messagesQueue = config('campaigns.queues.messages', 'campaign-messages');

            $campaigns = Campaign::query()
                ->select(['id', 'organization_id', 'status', 'uuid', 'created_by', 'template_id'])
                ->where('status', 'ongoing')
                ->where('organization_id', $this->organizationId)
                ->whereHas('campaignLogs', fn ($query) => $query->whereIn('status', ['pending', 'failed']))
                ->orderBy('id')
                ->get();

            $chunksByCampaign = [];
            $campaignsProcessed = [];
            $selectedCount = 0;
            $shouldRedispatch = false;

            foreach ($campaigns as $campaign) {
                if ($selectedCount >= $waveSize) {
                    $shouldRedispatch = true;
                    break;
                }

                $remainingForWave = $waveSize - $selectedCount;
                $limit = min($perCampaignWaveSize, $remainingForWave);
                $candidateLogs = $this->eligibleLogsForCampaign($campaign->id, $limit, $retryEnabled, $maxRetries);

                if ($candidateLogs->isEmpty()) {
                    $this->markCampaignAsCompleted($campaign->id);
                    continue;
                }

                $logs = $this->reserveLogsForDispatch($candidateLogs, $limit);
                if ($logs->isEmpty()) {
                    $shouldRedispatch = true;
                    continue;
                }

                $campaignsProcessed[] = $campaign->id;
                $selectedCount += $logs->count();

                if ($logs->count() >= $limit || $candidateLogs->count() > $limit) {
                    $shouldRedispatch = true;
                }

                $chunksByCampaign[$campaign->id] = [
                    'campaign' => $campaign,
                    'chunks' => $logs->pluck('id')->chunk($chunkSize)->values(),
                ];
            }

            $dispatchedChunks = $this->dispatchRoundRobinChunks(
                $chunksByCampaign,
                $concurrency,
                $requestDelayMs,
                $messagesQueue
            );

            Log::info('ProcessCampaignMessagesJob wave dispatched', [
                'organization_id' => $this->organizationId,
                'campaigns' => $campaignsProcessed,
                'selected_messages' => $selectedCount,
                'dispatched_chunks' => $dispatchedChunks,
                'wave_size' => $waveSize,
                'per_campaign_wave_size' => $perCampaignWaveSize,
            ]);

            foreach ($campaignsProcessed as $campaignId) {
                $this->markCampaignAsCompleted($campaignId);
            }

            if ($shouldRedispatch) {
                self::dispatchForOrganization($this->organizationId)
                    ->onQueue(config('campaigns.queues.dispatchers', 'campaign-dispatchers'))
                    ->delay(now()->addSeconds((int) config('campaigns.dispatch.redispatch_delay_seconds', 5)));
            }

            $this->logDispatcherMetrics($startTime, count($campaignsProcessed));
        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: ' . $e->getMessage());
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    private function eligibleLogsForCampaign(int $campaignId, int $limit, bool $retryEnabled, int $maxRetries)
    {
        $scanLimit = max($limit, min($limit * 5, $limit + 5000));

        return CampaignLog::select(['id', 'campaign_id', 'contact_id', 'status', 'chat_id'])
            ->where('campaign_id', $campaignId)
            ->where(function ($query) use ($retryEnabled, $maxRetries) {
                $query->where('status', 'pending');

                if ($retryEnabled && $maxRetries > 0) {
                    $query->orWhere(function ($failedQuery) use ($maxRetries) {
                        $failedQuery->where('status', 'failed')
                            ->whereRaw(
                                '(select count(*) from campaign_log_retries where campaign_log_retries.campaign_log_id = campaign_logs.id) < ?',
                                [$maxRetries]
                            );
                    });
                }
            })
            ->orderBy('id')
            ->limit($scanLimit)
            ->get();
    }

    private function reserveLogsForDispatch($logs, int $limit)
    {
        $ttl = max(60, (int) config('campaigns.dispatch.reservation_ttl_seconds', 900));
        $cache = Cache::store('redis');

        return $logs
            ->filter(fn (CampaignLog $log): bool => $cache->add($this->reservationKey($log->id), 1, $ttl))
            ->take($limit)
            ->values();
    }

    private function dispatchRoundRobinChunks(array $chunksByCampaign, int $concurrency, int $requestDelayMs, string $queue): int
    {
        $dispatched = 0;

        while (! empty($chunksByCampaign)) {
            foreach ($chunksByCampaign as $campaignId => &$entry) {
                $chunk = $entry['chunks']->shift();
                if (! $chunk) {
                    unset($chunksByCampaign[$campaignId]);
                    continue;
                }

                $campaign = $entry['campaign'];
                ProcessCampaignChunkJob::dispatch(
                    logIds: $chunk->values()->all(),
                    organizationId: $this->organizationId,
                    campaignId: (int) $campaignId,
                    templateId: (int) $campaign->template_id,
                    createdBy: (int) $campaign->created_by,
                    concurrency: $concurrency,
                    requestDelayMs: $requestDelayMs,
                )->onQueue($queue);

                $dispatched++;
            }
            unset($entry);
        }

        return $dispatched;
    }

    private function reservationKey(int $campaignLogId): string
    {
        return 'campaign:dispatch:reserved:'.$campaignLogId;
    }

    /**
     * Log timing metrics without enabling DB query logging in production workers.
     */
    private function logDispatcherMetrics(float $startTime, int $campaignsProcessed): void
    {
        $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('ProcessCampaignMessagesJob metrics', [
            'organization_id' => $this->organizationId,
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

    private function getChunkSize(array $orgMetadata): int
    {
        $default = config('campaigns.chunk_size', 50);
        $orgOverride = $orgMetadata['campaigns']['chunk_size'] ?? null;

        return $orgOverride !== null ? max(1, (int) $orgOverride) : $default;
    }

    private function getConcurrency(array $orgMetadata): int
    {
        $default = config('campaigns.concurrency', 10);
        $orgOverride = $orgMetadata['campaigns']['concurrency'] ?? null;

        return $orgOverride !== null ? max(1, (int) $orgOverride) : $default;
    }

    private function getRequestDelayMs(array $orgMetadata): int
    {
        $default = config('campaigns.request_delay_ms', 100);
        $orgOverride = $orgMetadata['campaigns']['request_delay_ms'] ?? null;

        return $orgOverride !== null ? max(0, (int) $orgOverride) : $default;
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
            $campaign->completed_at = $campaign->completed_at ?? now();
            $campaign->save();
            Log::info("Campaign {$campaignId} marked as completed.");
        }
    }
}
