<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignCompletionService;
use Illuminate\Console\Command;

class ReconcileCampaignCompletion extends Command
{
    protected $signature = 'campaigns:reconcile-completion {--campaign-id= : Reconcile one campaign only}';

    protected $description = 'Complete ongoing campaigns that have no remaining executable messages.';

    public function handle(): int
    {
        $query = Campaign::query()->where('status', 'ongoing')->orderBy('id');

        if ($campaignId = $this->option('campaign-id')) {
            $query->whereKey((int) $campaignId);
        }

        $completed = 0;
        $inspected = 0;

        $query->select(['id'])->chunkById(200, function ($campaigns) use (&$completed, &$inspected): void {
            foreach ($campaigns as $campaign) {
                $inspected++;
                $completed += CampaignCompletionService::completeIfFinished((int) $campaign->id) ? 1 : 0;
            }
        });

        $this->info("Inspected {$inspected} ongoing campaigns; completed {$completed}.");

        return self::SUCCESS;
    }
}
