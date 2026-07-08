<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ResendRateLimitedCampaign extends Command
{
    protected $signature = 'campaign:resend-rate-limited
                            {campaign : Campaign ID or UUID}
                            {--force : Skip confirmation}
                            {--no-dispatch : Reset logs without dispatching the campaign job}';

    protected $description = 'Reset WhatsApp rate-limited campaign logs, clear dispatch reservations, and dispatch sending';

    public function handle(): int
    {
        $campaign = $this->resolveCampaign();
        if (! $campaign) {
            return 1;
        }

        $query = $this->rateLimitedLogsQuery($campaign->id);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->warn('No rate-limited campaign logs found for this campaign.');
            $this->line('If logs are pending without rate_limit_error_code=130429, check queue workers and scheduler.');

            return 1;
        }

        $this->info("Campaign {$campaign->id} ({$campaign->uuid})");
        $this->line("Rate-limited logs to reset/dispatch: {$count}");

        if (! $this->option('force') && ! $this->confirm('Continue?', true)) {
            $this->info('Cancelled.');

            return 0;
        }

        $resetCount = 0;
        $reservationCount = 0;

        (clone $query)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use (&$reservationCount): void {
                foreach ($logs as $log) {
                    if (Cache::store('redis')->forget('campaign:dispatch:reserved:'.$log->id)) {
                        $reservationCount++;
                    }
                }
            });

        (clone $query)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use (&$resetCount): void {
                $ids = $logs->pluck('id')->all();
                $resetCount += CampaignLog::whereIn('id', $ids)->update([
                    'status' => 'pending',
                    'retry_count' => 0,
                    'updated_at' => now(),
                ]);
            });

        $campaign->update([
            'status' => 'ongoing',
            'started_at' => now(),
            'completed_at' => null,
        ]);

        if (! $this->option('no-dispatch')) {
            ProcessCampaignMessagesJob::dispatchForOrganization($campaign->organization_id)
                ->onQueue(config('campaigns.queues.dispatchers', 'campaign-dispatchers'));
        }

        $this->info("Reset logs: {$resetCount}");
        $this->info("Cleared dispatch reservations: {$reservationCount}");
        $this->info('Campaign marked ongoing.');

        if (! $this->option('no-dispatch')) {
            $this->info('ProcessCampaignMessagesJob dispatched.');
        }

        return 0;
    }

    private function resolveCampaign(): ?Campaign
    {
        $identifier = $this->argument('campaign');

        $campaign = is_numeric($identifier)
            ? Campaign::find((int) $identifier)
            : Campaign::where('uuid', $identifier)->first();

        if (! $campaign) {
            $this->error("Campaign not found: {$identifier}");

            return null;
        }

        return $campaign;
    }

    private function rateLimitedLogsQuery(int $campaignId): Builder
    {
        $query = CampaignLog::where('campaign_id', $campaignId)
            ->where(function (Builder $query): void {
                $query->whereIn('status', ['failed', 'permanently_failed', 'pending'])
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', 'success')
                            ->whereNull('chat_id');
                    });
            });

        if (Schema::hasColumn('campaign_logs', 'rate_limit_error_code')) {
            return $query->where('rate_limit_error_code', '130429');
        }

        return $query->where(function (Builder $query): void {
            $query->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
        });
    }
}
