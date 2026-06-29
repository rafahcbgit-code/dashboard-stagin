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

class CreateCampaignLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 3;

    public function handle()
    {
        try {
            $campaigns = Campaign::where('status', 'scheduled')
                ->with('organization')
                ->whereNull('deleted_at')
                ->cursor();

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
        /* $lock = Cache::lock('campaign_logs_create_'.$campaign->id, 120);

        if (! $lock->get()) {
            Log::info('Skipping campaign log creation due to active lock', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        } */

        $contacts = $this->getContactsForCampaign($campaign);

        try {
            if ($this->createCampaignLogs($campaign, $contacts)) {
                Campaign::where('uuid', $campaign->uuid)->update(['status' => 'ongoing']);
                Log::info("Campaign {$campaign->uuid} marked ongoing.");

                // Dispatch ProcessCampaignMessagesJob for this org (Story 2.1: AC-2.1.1)
                ProcessCampaignMessagesJob::dispatchForOrganization($campaign->organization_id);
                Log::info("ProcessCampaignMessagesJob dispatched for org {$campaign->organization_id}.");
            } else {
                Log::info("Campaign {$campaign->uuid} not marked ongoing.");
            }
        } finally {
            // optional($lock)->release();
        }
    }

    protected function getContactsForCampaign(Campaign $campaign)
    {
        if (empty($campaign->contact_group_id) || $campaign->contact_group_id === '0') {
            return Contact::where('organization_id', $campaign->organization_id)
                ->whereNull('deleted_at')
                ->where('is_subscribed', 1)
                ->get();
        }

        return Contact::whereHas('contactGroups', function ($query) use ($campaign) {
            $query->where('contact_groups.id', $campaign->contact_group_id);
        })
            ->whereNull('deleted_at')
            ->where('is_subscribed', 1)
            ->get();
    }

    protected function createCampaignLogs(Campaign $campaign, $contacts)
    {
        $contactIds = $contacts->pluck('id');

        // Fetch existing logs
        $existingLogs = CampaignLog::where('campaign_id', $campaign->id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id')
            ->toArray();

        // Filter out contacts that already have logs and contacts without phone
        $contactsWithoutPhone = $contacts->filter(fn($c) => empty($c->phone))->pluck('id');
        $newContacts = $contactIds->diff($existingLogs)->diff($contactsWithoutPhone);

        if ($newContacts->isEmpty()) {
            return false; // nothing new
        }

        // Log skipped contacts
        if ($contactsWithoutPhone->isNotEmpty()) {
            Log::warning('Skipped contacts without phone for campaign', [
                'campaign_id' => $campaign->id,
                'skipped_contact_ids' => $contactsWithoutPhone->values()->toArray(),
            ]);
        }

        // Prepare new campaign logs
        $campaignLogs = $newContacts->map(function ($contactId) use ($campaign) {
            return [
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Insert new logs in chunks
        foreach (array_chunk($campaignLogs, 1000) as $chunk) {
            CampaignLog::insert($chunk);
            CampaignStatsService::recordInsertedPending((int) $campaign->id, count($chunk));
        }

        return true;
    }
}
