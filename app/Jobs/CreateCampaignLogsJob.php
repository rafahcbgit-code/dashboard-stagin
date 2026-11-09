<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Services\CampaignStatsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateCampaignLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 3;

    public function __construct(
        public ?int $campaignId = null
    ) {
        $this->queue = config('campaigns.queues.logs', 'campaign-logs');
    }

    public function handle()
    {
        try {
            $campaigns = Campaign::where('status', 'scheduled')
                ->with('organization')
                ->whereNull('deleted_at')
                ->when($this->campaignId !== null, fn ($query) => $query->where('id', $this->campaignId))
                ->lazyById(100);

            foreach ($campaigns as $campaign) {
                $timezone = $this->getOrganizationTimezone($campaign->organization);
                $scheduledAt = Carbon::parse($campaign->scheduled_at, 'UTC')->timezone($timezone);

                if ($scheduledAt->lte(Carbon::now($timezone))) {
                    $this->processCampaign($campaign);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in CreateCampaignLogsJob: '.$e->getMessage());
            throw $e;
        }
    }

    protected function getOrganizationTimezone($organization)
    {
        if (! $organization) {
            return 'UTC';
        }

        $metadata = $organization->metadata;
        $metadata = isset($metadata) ? json_decode($metadata, true) : null;

        return $metadata['timezone'] ?? 'UTC';
    }

    protected function processCampaign(Campaign $campaign)
    {
        $lock = Cache::store('redis')->lock('campaign_logs_create_'.$campaign->id, 3600);

        if (! $lock->get()) {
            Log::info('Skipping campaign log creation due to active lock', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        try {
            $campaign->refresh();
            if ($campaign->status !== 'scheduled') {
                return;
            }
            $contacts = $this->getContactsForCampaign($campaign);

            if ($this->createCampaignLogs($campaign, $contacts)) {
                $started = Campaign::where('id', $campaign->id)->where('status', 'scheduled')->update([
                    'status' => 'ongoing',
                    'started_at' => $campaign->started_at ?? now(),
                    'completed_at' => null,
                ]);
                if (! $started) {
                    return;
                }
                Log::info("Campaign {$campaign->uuid} marked ongoing.");

                // Dispatch ProcessCampaignMessagesJob for this org (Story 2.1: AC-2.1.1)
                ProcessCampaignMessagesJob::dispatchForOrganization($campaign->organization_id)
                    ->onQueue(config('campaigns.queues.dispatchers', 'campaign-dispatchers'));
                Log::info("ProcessCampaignMessagesJob dispatched for org {$campaign->organization_id}.");
            } elseif (CampaignLog::where('campaign_id', $campaign->id)->exists()) {
                // Resume after a worker restart that inserted logs before changing the campaign state.
                Campaign::where('id', $campaign->id)->where('status', 'scheduled')->update(['status' => 'ongoing', 'started_at' => $campaign->started_at ?? now()]);
                ProcessCampaignMessagesJob::dispatchForOrganization($campaign->organization_id);
            } else {
                Campaign::where('id', $campaign->id)->where('status', 'scheduled')->update(['status' => 'completed', 'completed_at' => now()]);
            }
        } finally {
            optional($lock)->release();
        }
    }

    protected function getContactsForCampaign(Campaign $campaign)
    {
        if (empty($campaign->contact_group_id) || $campaign->contact_group_id === '0') {
            return Contact::where('organization_id', $campaign->organization_id)
                ->whereNull('deleted_at')
                ->where('is_subscribed', 1)
                ->where(function ($query) {
                    $query->whereNull('is_blocked')->orWhere('is_blocked', false);
                })
                ->whereNotNull('phone')
                ->where('phone', '<>', '');
        }

        return Contact::whereHas('contactGroups', function ($query) use ($campaign) {
            $query->where('contact_groups.id', $campaign->contact_group_id);
        })
            ->where('organization_id', $campaign->organization_id)
            ->whereNull('deleted_at')
            ->where('is_subscribed', 1)
            ->where(function ($query) {
                $query->whereNull('is_blocked')->orWhere('is_blocked', false);
            })
            ->whereNotNull('phone')
            ->where('phone', '<>', '');
    }

    protected function createCampaignLogs(Campaign $campaign, $contacts)
    {
        $inserted = false;
        $contacts->select('contacts.id')->chunkById(1000, function ($batch) use ($campaign, &$inserted) {
            DB::transaction(function () use ($batch, $campaign, &$inserted) {
                $existing = CampaignLog::where('campaign_id', $campaign->id)
                    ->whereIn('contact_id', $batch->pluck('id'))->pluck('contact_id')->all();
                $now = now();
                $rows = $batch->whereNotIn('id', $existing)->map(fn ($contact) => [
                    'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ])->all();
                if ($rows) {
                    CampaignLog::insert($rows);
                    CampaignStatsService::recordInsertedPending((int) $campaign->id, count($rows));
                    $inserted = true;
                }
            });
        }, 'contacts.id', 'id');
        return $inserted;
    }

}
