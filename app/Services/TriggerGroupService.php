<?php

namespace App\Services;

use App\Models\TriggerGroup;
use Illuminate\Support\Facades\DB;

class TriggerGroupService
{
    /**
     * Get all trigger groups for an organization, ordered by priority.
     */
    public function listAll(int $organizationId, ?string $searchTerm = null)
    {
        return TriggerGroup::forOrganization($organizationId)
            ->with('flow')
            ->orderedByPriority()
            ->when($searchTerm, function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            })
            ->paginate(10)
            ->withQueryString();
    }

    /**
     * Create a new trigger group.
     */
    public function create(int $organizationId, array $data): TriggerGroup
    {
        // Auto-assign next priority if not set
        if (!isset($data['priority'])) {
            $maxPriority = TriggerGroup::forOrganization($organizationId)
                ->max('priority');
            $data['priority'] = ($maxPriority ?? -1) + 1;
        }

        $data['organization_id'] = $organizationId;

        return TriggerGroup::create($data);
    }

    /**
     * Update a trigger group.
     */
    public function update(string $uuid, array $data): ?TriggerGroup
    {
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        if (!$triggerGroup) {
            return null;
        }

        $triggerGroup->update($data);
        return $triggerGroup->fresh();
    }

    /**
     * Delete a trigger group (soft delete).
     * System triggers cannot be deleted, only disabled.
     */
    public function delete(string $uuid): bool
    {
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        if (!$triggerGroup) {
            return false;
        }

        if ($triggerGroup->is_system) {
            return false;
        }

        $triggerGroup->delete();
        return true;
    }

    /**
     * Reorder trigger groups based on an array of UUIDs in the desired order.
     */
    public function reorder(int $organizationId, array $orderedUuids): void
    {
        DB::transaction(function () use ($organizationId, $orderedUuids) {
            foreach ($orderedUuids as $index => $uuid) {
                TriggerGroup::forOrganization($organizationId)
                    ->where('uuid', $uuid)
                    ->update(['priority' => $index]);
            }
        });
    }

    /**
     * Toggle a trigger group's enabled state.
     */
    public function toggleEnabled(string $uuid, bool $enabled): ?TriggerGroup
    {
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        if (!$triggerGroup) {
            return null;
        }

        $triggerGroup->update(['is_enabled' => $enabled]);
        return $triggerGroup->fresh();
    }

    /**
     * Link a flow to a trigger group.
     */
    public function linkFlow(string $uuid, int $flowId): ?TriggerGroup
    {
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        if (!$triggerGroup) {
            return null;
        }

        $triggerGroup->update(['flow_id' => $flowId]);
        return $triggerGroup->fresh();
    }

    /**
     * Unlink the flow from a trigger group.
     */
    public function unlinkFlow(string $uuid): ?TriggerGroup
    {
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        if (!$triggerGroup) {
            return null;
        }

        $triggerGroup->update(['flow_id' => null]);
        return $triggerGroup->fresh();
    }

    /**
     * Initialize system triggers for an organization.
     * Creates: Welcome, Greeting, Away, Fallback.
     */
    public function initializeSystemTriggers(int $organizationId): void
    {
        $systemTriggers = [
            [
                'name' => 'Welcome',
                'type' => 'new_contact',
                'rate_limit_type' => 'lifetime',
                'priority' => 0,
                'is_system' => true,
                'is_enabled' => true,
                'keywords' => null,
                'match_mode' => 'exact',
            ],
            [
                'name' => 'Greeting',
                'type' => 'greeting',
                'rate_limit_type' => '24h',
                'priority' => 1,
                'is_system' => true,
                'is_enabled' => true,
                'keywords' => null,
                'match_mode' => 'exact',
            ],
            [
                'name' => 'Away',
                'type' => 'away',
                'rate_limit_type' => '24h',
                'priority' => 2,
                'is_system' => true,
                'is_enabled' => false,
                'keywords' => null,
                'match_mode' => 'exact',
            ],
            [
                'name' => 'Fallback',
                'type' => 'fallback',
                'rate_limit_type' => 'none',
                'priority' => 999,
                'is_system' => true,
                'is_enabled' => true,
                'keywords' => null,
                'match_mode' => 'exact',
            ],
        ];

        foreach ($systemTriggers as $trigger) {
            // Only create if it doesn't already exist
            $exists = TriggerGroup::forOrganization($organizationId)
                ->where('type', $trigger['type'])
                ->where('is_system', true)
                ->exists();

            if (!$exists) {
                TriggerGroup::create(array_merge($trigger, [
                    'organization_id' => $organizationId,
                ]));
            }
        }
    }

    /**
     * Get the active flow for a specific trigger type.
     */
    public function getActiveFlow(int $organizationId, string $type): ?TriggerGroup
    {
        return TriggerGroup::forOrganization($organizationId)
            ->enabled()
            ->byType($type)
            ->whereNotNull('flow_id')
            ->first();
    }
}