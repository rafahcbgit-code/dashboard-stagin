<?php

namespace App\Services;

use App\Models\Organization;

class BotSettingsService
{
    const DEFAULTS = [
        'fallback_threshold' => 3,
        'flow_timeout_minutes' => 30,
        'ai_fallback_enabled' => false,
        'ai_fallback_confidence' => 75,
        'fallback_menu_enabled' => false,
        'fallback_menu_message' => "I didn't quite understand that. What would you like to do?",
        'fallback_menu_start_over_flow_id' => null,
        'fallback_menu_help_flow_id' => null,
        'fallback_menu_route_to_agent' => true,
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
     * Check if fallback menu is enabled for an organization.
     */
    public function isFallbackMenuEnabled(int $organizationId): bool
    {
        return (bool) $this->getSetting($organizationId, 'fallback_menu_enabled', self::DEFAULTS['fallback_menu_enabled']);
    }

    /**
     * Get fallback menu config for an organization.
     */
    public function getFallbackMenuConfig(int $organizationId): array
    {
        return [
            'enabled' => $this->isFallbackMenuEnabled($organizationId),
            'message' => $this->getSetting($organizationId, 'fallback_menu_message', self::DEFAULTS['fallback_menu_message']),
            'start_over_flow_id' => $this->getSetting($organizationId, 'fallback_menu_start_over_flow_id'),
            'help_flow_id' => $this->getSetting($organizationId, 'fallback_menu_help_flow_id'),
            'route_to_agent' => (bool) $this->getSetting($organizationId, 'fallback_menu_route_to_agent', self::DEFAULTS['fallback_menu_route_to_agent']),
        ];
    }
}