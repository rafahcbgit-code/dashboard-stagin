<?php

namespace App\Jobs;

use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;

class ProcessCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 3600;
    public $tries = 3;

    public function handle()
    {
        try {
            // Track campaigns to check completion later
            $campaignsProcessed = [];

            // Process logs in chunks to avoid memory issues
            CampaignLog::with(['campaign.organization', 'contact'])
                ->whereIn('status', ['pending', 'failed'])
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing');
                })
                ->chunk(500, function ($logs) use (&$campaignsProcessed) {
                    $jobs = [];

                    foreach ($logs as $log) {
                        $orgMetadata = json_decode($log->campaign->organization->metadata ?? '{}', true);
                        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
                        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
                        $maxRetries = count($retryIntervals);

                        // Get actual retry count from database relationship
                        $retryCount = $log->retries()->count();

                        // Add job if pending or failed with retries remaining
                        if ($log->status === 'pending' || 
                            ($log->status === 'failed' && $retryEnabled && $retryCount < $maxRetries)) {
                            $jobs[] = new ProcessSingleCampaignLogJob($log);
                        }

                        // Track campaign IDs
                        if (!in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    // Dispatch jobs to queue
                    if (!empty($jobs)) {
                        Bus::batch($jobs)
                            ->allowFailures()
                            ->onQueue('campaign-messages')
                            ->dispatch();
                    }
                });

            foreach ($campaignsProcessed as $campaignId) {
                $this->markCampaignAsCompleted($campaignId);
            }

        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: ' . $e->getMessage());
            throw $e;
        }
    }

    private function markCampaignAsCompleted($campaignId)
    {
        $campaign = Campaign::with('organization')->find($campaignId);
        
        if (!$campaign || $campaign->status !== 'ongoing') {
            return;
        }

        $orgMetadata = json_decode($campaign->organization->metadata ?? '{}', true);
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

        if (!$failedLogsCanRetry) {
            $campaign->status = 'completed';
            $campaign->save();
            Log::info("Campaign {$campaignId} marked as completed.");
        }
    }
}