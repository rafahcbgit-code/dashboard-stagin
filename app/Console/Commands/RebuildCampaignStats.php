<?php

namespace App\Console\Commands;

use App\Services\CampaignStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildCampaignStats extends Command
{
    protected $signature = 'campaigns:stats-rebuild
        {--organization= : Rebuild stats for one organization id}
        {--campaign= : Rebuild stats for one campaign id}';

    protected $description = 'Rebuild stored campaign message statistics.';

    public function handle(): int
    {
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;
        $campaignId = $this->option('campaign') ? (int) $this->option('campaign') : null;

        if ($campaignId) {
            CampaignStatsService::rebuildCampaignIds([$campaignId]);
            $this->info("Rebuilt stats for campaign {$campaignId}.");
            return self::SUCCESS;
        }

        if ($organizationId) {
            CampaignStatsService::rebuildForOrganization($organizationId);
            $this->info("Rebuilt stats for organization {$organizationId}.");
            return self::SUCCESS;
        }

        DB::table('campaigns')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($campaigns): void {
                CampaignStatsService::rebuildCampaignIds($campaigns->pluck('id'));
                $this->line('Rebuilt through campaign id '.$campaigns->max('id'));
            });

        $this->info('Campaign stats rebuild completed.');

        return self::SUCCESS;
    }
}
