<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Applies the configured failed-contact group policy for terminal campaign sends. */
class CampaignFailedContactService
{
    public static function moveToConfiguredGroup(int $contactId, array $campaignSettings): void
    {
        if (empty($campaignSettings['move_failed_contacts_to_group'])) {
            return;
        }

        $groupUuid = $campaignSettings['failed_campaign_group'] ?? null;
        if (empty($groupUuid)) {
            return;
        }

        $failedGroupId = DB::table('contact_groups')->where('uuid', $groupUuid)->value('id');
        if (! $failedGroupId) {
            Log::warning('Failed to move contact: Group with UUID '.$groupUuid.' does not exist.');
            return;
        }

        $affectedGroupIds = DB::table('contact_contact_group')
            ->where('contact_id', $contactId)
            ->pluck('contact_group_id')
            ->all();

        DB::table('contact_contact_group')->where('contact_id', $contactId)->delete();
        DB::table('contact_contact_group')->insert([
            'contact_id' => $contactId,
            'contact_group_id' => $failedGroupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $affectedGroupIds[] = (int) $failedGroupId;
        ContactGroupCountService::rebuildGroups($affectedGroupIds);
    }
}
