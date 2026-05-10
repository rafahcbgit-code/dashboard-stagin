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
        'escalation_transfer_message' => "You're being connected to a team member who can help. Please hold on a moment.",
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

        return array_merge(self::DEFAULTS, $botSettings);
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

}