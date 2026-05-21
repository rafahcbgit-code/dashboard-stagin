<?php

namespace App\Services;

use App\Models\Organization;

class BotSettingsService
{
    const DEFAULTS = [
        'automation_enabled' => true,
        'fallback_threshold' => 3,
        'flow_timeout_minutes' => 30,
        'ai_fallback_enabled' => false,
        'ai_fallback_confidence' => 75,
        'escalation_transfer_message_enabled' => false,
        'escalation_transfer_message' => "You're being connected to a team member who can help. Please hold on a moment.",
        'ai_followup_enabled' => false,
        'ai_followup_question' => 'Was this helpful?',
        'ai_followup_yes_text' => 'Yes, this helped',
        'ai_followup_no_text' => 'No, I need more help',
        'flow_rejoin_enabled' => false,
        'flow_rejoin_reminder_minutes' => 15,
        'flow_rejoin_message' => "You have an active conversation with us. Reply to continue where you left off.",
        'flow_rejoin_flow_ids' => [],
    ];

    /**
     * Get bot settings for an organization.
     */
    public function get(int $organizationId): array
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return self::DEFAULTS;
        }

        $metadata = $org->metadata ? json_decode($org->metadata, true) : [];
        $botSettings = $metadata['bot_settings'] ?? [];

        $settings = array_merge(self::DEFAULTS, $botSettings);
        $settings['flow_rejoin_reminder_minutes'] = max(5, (int) ($settings['flow_rejoin_reminder_minutes'] ?? self::DEFAULTS['flow_rejoin_reminder_minutes']));
        $settings['flow_rejoin_flow_ids'] = $this->normalizeFlowIds($settings['flow_rejoin_flow_ids'] ?? []);

        if (($settings['flow_rejoin_enabled'] ?? false) && empty($settings['flow_rejoin_flow_ids'])) {
            $settings['flow_rejoin_enabled'] = false;
        }

        return $settings;
    }

    /**
     * Update bot settings for an organization.
     */
    public function update(int $organizationId, array $settings): array
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return self::DEFAULTS;
        }

        $metadata = $org->metadata ? json_decode($org->metadata, true) : [];

        $botSettings = array_merge(
            self::DEFAULTS,
            $metadata['bot_settings'] ?? [],
            $settings
        );

        $metadata['bot_settings'] = $botSettings;
        $org->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $org->save();

        return $botSettings;
    }

    /**
     * Get a single setting value.
     */
    public function getSetting(int $organizationId, string $key, $default = null)
    {
        $settings = $this->get($organizationId);
        return $settings[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * Check if automation is enabled for an organization.
     */
    public function isAutomationEnabled(int $organizationId): bool
    {
        return (bool) $this->getSetting($organizationId, 'automation_enabled', self::DEFAULTS['automation_enabled']);
    }

    /**
     * Get fallback threshold for an organization.
     */
    public function getFallbackThreshold(int $organizationId): int
    {
        return (int) $this->getSetting($organizationId, 'fallback_threshold', self::DEFAULTS['fallback_threshold']);
    }

    /**
     * Get flow timeout in minutes for an organization.
     */
    public function getFlowTimeoutMinutes(int $organizationId): int
    {
        return (int) $this->getSetting($organizationId, 'flow_timeout_minutes', self::DEFAULTS['flow_timeout_minutes']);
    }

    /**
     * Check if AI follow-up feedback is enabled.
     */
    public function isAiFollowupEnabled(int $organizationId): bool
    {
        return (bool) $this->getSetting($organizationId, 'ai_followup_enabled', self::DEFAULTS['ai_followup_enabled']);
    }

    /**
     * Get the follow-up question text.
     */
    public function getAiFollowupQuestion(int $organizationId): string
    {
        return $this->getSetting($organizationId, 'ai_followup_question', self::DEFAULTS['ai_followup_question']);
    }

    /**
     * Get the follow-up "Yes" button text.
     */
    public function getAiFollowupYesText(int $organizationId): string
    {
        return $this->getSetting($organizationId, 'ai_followup_yes_text', self::DEFAULTS['ai_followup_yes_text']);
    }

    /**
     * Get the follow-up "No" button text.
     */
    public function getAiFollowupNoText(int $organizationId): string
    {
        return $this->getSetting($organizationId, 'ai_followup_no_text', self::DEFAULTS['ai_followup_no_text']);
    }

    /**
     * Check if flow rejoin reminder is enabled.
     */
    public function isFlowRejoinEnabled(int $organizationId): bool
    {
        return (bool) $this->getSetting($organizationId, 'flow_rejoin_enabled', self::DEFAULTS['flow_rejoin_enabled']);
    }

    /**
     * Get the flow rejoin reminder delay in minutes.
     */
    public function getFlowRejoinReminderMinutes(int $organizationId): int
    {
        return max(5, (int) $this->getSetting($organizationId, 'flow_rejoin_reminder_minutes', self::DEFAULTS['flow_rejoin_reminder_minutes']));
    }

    /**
     * Get the flow rejoin reminder message text.
     */
    public function getFlowRejoinMessage(int $organizationId): string
    {
        return $this->getSetting($organizationId, 'flow_rejoin_message', self::DEFAULTS['flow_rejoin_message']);
    }

    /**
     * Get flow IDs that should receive rejoin reminders.
     */
    public function getFlowRejoinFlowIds(int $organizationId): array
    {
        $flowIds = $this->getSetting($organizationId, 'flow_rejoin_flow_ids', self::DEFAULTS['flow_rejoin_flow_ids']);

        return $this->normalizeFlowIds($flowIds);
    }

    private function normalizeFlowIds($flowIds): array
    {
        if (!is_array($flowIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $flowIds)));
    }

}
