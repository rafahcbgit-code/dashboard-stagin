<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Support\Facades\Log;

/** Safely closes campaigns once no executable work remains. */
class CampaignCompletionService
{
    public static function completeIfFinished(int $campaignId): bool
    {
        $campaign = Campaign::find($campaignId);

        if (! $campaign || $campaign->status !== 'ongoing') {
            return false;
        }

        if (CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists()) {
            return false;
        }

        $metadata = OrganizationService::getCachedMetadata((int) $campaign->organization_id);
        $maxRetries = count(CampaignRetrySettings::intervals($metadata));
        $retryEnabled = CampaignRetrySettings::enabled($metadata);

        $hasRetriableFailure = $retryEnabled && CampaignLog::where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->withCount('retries')
            ->get()
            ->contains(fn (CampaignLog $log): bool => $log->retries_count < $maxRetries);

        if ($hasRetriableFailure) {
            return false;
        }

        $campaign->update([
            'status' => 'completed',
            'completed_at' => $campaign->completed_at ?? now(),
        ]);

        Log::info("Campaign {$campaignId} marked as completed.");

        return true;
    }
}
