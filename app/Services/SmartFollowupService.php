<?php

namespace App\Services;

use App\Models\SmartFollowup;

class SmartFollowupService
{
    public function listRejoinReminders(int $organizationId)
    {
        return SmartFollowup::forOrganization($organizationId)
            ->rejoinReminder()
            ->with('flow:id,uuid,name,status')
            ->latest()
            ->get();
    }

    public function createRejoinReminder(int $organizationId, array $data): SmartFollowup
    {
        return SmartFollowup::create([
            'organization_id' => $organizationId,
            'type' => SmartFollowup::TYPE_REJOIN_REMINDER,
            'flow_id' => $data['flow_id'],
            'message' => $data['message'],
            'reminder_minutes' => max(5, (int) $data['reminder_minutes']),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);
    }

    public function updateRejoinReminder(int $organizationId, string $uuid, array $data): ?SmartFollowup
    {
        $followup = SmartFollowup::forOrganization($organizationId)
            ->where('uuid', $uuid)
            ->rejoinReminder()
            ->first();

        if (!$followup) {
            return null;
        }

        $followup->update([
            'message' => $data['message'] ?? $followup->message,
            'reminder_minutes' => isset($data['reminder_minutes'])
                ? max(5, (int) $data['reminder_minutes'])
                : $followup->reminder_minutes,
            'is_enabled' => isset($data['is_enabled'])
                ? (bool) $data['is_enabled']
                : $followup->is_enabled,
        ]);

        return $followup->fresh('flow:id,uuid,name,status');
    }

    public function delete(int $organizationId, string $uuid): bool
    {
        $followup = SmartFollowup::forOrganization($organizationId)
            ->where('uuid', $uuid)
            ->first();
        if (!$followup) {
            return false;
        }

        return (bool) $followup->delete();
    }

    public function toggle(int $organizationId, string $uuid, bool $enabled): ?SmartFollowup
    {
        $followup = SmartFollowup::forOrganization($organizationId)
            ->where('uuid', $uuid)
            ->first();
        if (!$followup) {
            return null;
        }

        $followup->update(['is_enabled' => $enabled]);

        return $followup->fresh('flow:id,uuid,name,status');
    }
}
