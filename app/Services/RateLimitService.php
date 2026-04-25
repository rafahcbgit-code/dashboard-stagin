<?php

namespace App\Services;

use App\Models\MessageRateLimit;
use App\Models\TriggerGroup;
use Carbon\Carbon;

class RateLimitService
{
    protected BotSettingsService $botSettings;

    public function __construct(BotSettingsService $botSettings)
    {
        $this->botSettings = $botSettings;
    }
    /**
     * Check if a trigger can fire for a given contact.
     *
     * @param int $organizationId
     * @param int $contactId
     * @param int $triggerGroupId
     * @return bool True if the trigger can fire (not rate-limited)
     */
    public function canTrigger(int $organizationId, int $contactId, int $triggerGroupId): bool
    {
        $triggerGroup = TriggerGroup::find($triggerGroupId);
        if (!$triggerGroup) {
            return false;
        }

        // If rate limit type is 'none', always allow
        if ($triggerGroup->rate_limit_type === 'none') {
            return true;
        }

        $rateLimit = MessageRateLimit::forOrganization($organizationId)
            ->forContact($contactId)
            ->forTriggerGroup($triggerGroupId)
            ->first();

        if (!$rateLimit) {
            return true;
        }

        switch ($triggerGroup->rate_limit_type) {
            case 'lifetime':
                // Can only trigger once per contact, ever
                return $rateLimit->trigger_count === 0;

            case '24h':
                // Can only trigger once per 24 hours
                if (!$rateLimit->last_triggered_at) {
                    return true;
                }
                return $rateLimit->last_triggered_at->diffInHours(now()) >= 24;

            default:
                return true;
        }
    }

    /**
     * Record that a trigger has fired for a contact.
     *
     * @param int $organizationId
     * @param int $contactId
     * @param int $triggerGroupId
     * @return MessageRateLimit
     */
    public function recordTrigger(int $organizationId, int $contactId, int $triggerGroupId): MessageRateLimit
    {
        return MessageRateLimit::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'trigger_group_id' => $triggerGroupId,
            ],
            [
                'last_triggered_at' => now(),
                'trigger_count' => MessageRateLimit::raw('trigger_count + 1'),
            ]
        );
    }

    /**
     * Reset rate limit for a contact (e.g., when "Start Over" is clicked in fallback menu).
     */
    public function resetTrigger(int $organizationId, int $contactId, int $triggerGroupId): bool
    {
        return MessageRateLimit::forOrganization($organizationId)
            ->forContact($contactId)
            ->forTriggerGroup($triggerGroupId)
            ->delete() > 0;
    }

    /**
     * Get the trigger count for a contact and trigger group.
     */
    public function getTriggerCount(int $organizationId, int $contactId, int $triggerGroupId): int
    {
        $rateLimit = MessageRateLimit::forOrganization($organizationId)
            ->forContact($contactId)
            ->forTriggerGroup($triggerGroupId)
            ->first();

        return $rateLimit ? $rateLimit->trigger_count : 0;
    }

    /**
     * Check fallback menu escalation threshold.
     * Uses the organization's bot_settings.fallback_threshold.
     *
     * @param int $organizationId
     * @param int $contactId
     * @return bool True if threshold exceeded (should escalate)
     */
    public function shouldEscalate(int $organizationId, int $contactId): bool
    {
        $threshold = $this->botSettings->getFallbackThreshold($organizationId);
        // Find the fallback trigger group for this org
        $fallbackTrigger = TriggerGroup::forOrganization($organizationId)
            ->byType('fallback')
            ->first();

        if (!$fallbackTrigger) {
            return false;
        }

        return $this->getTriggerCount($organizationId, $contactId, $fallbackTrigger->id) >= $threshold;
    }

    /**
     * Record a fallback menu trigger for rate limiting / escalation tracking.
     * Uses the fallback trigger group as the counter.
     */
    public function recordFallback(int $organizationId, int $contactId): void
    {
        $fallbackTrigger = TriggerGroup::forOrganization($organizationId)
            ->byType('fallback')
            ->first();

        if ($fallbackTrigger) {
            $this->recordTrigger($organizationId, $contactId, $fallbackTrigger->id);
        }
    }
}