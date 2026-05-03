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
        'fallback_menu_enabled' => false,
        'fallback_menu_message' => "I didn't quite understand that. What would you like to do?",
        'fallback_menu_start_over_flow_id' => null,
        'fallback_menu_help_flow_id' => null,
        'fallback_menu_route_to_agent' => true,
        'fallback_menu_button_1_label' => 'Start Over',
        'fallback_menu_button_2_label' => 'Help',
        'fallback_menu_button_3_label' => 'Talk to Agent',
        'fallback_menu_button_1_enabled' => true,
        'fallback_menu_button_2_enabled' => true,
        'fallback_menu_button_3_enabled' => true,
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
            'route_to_agent' => $this->getButton3Enabled($organizationId),
            'button_1_label' => $this->getSetting($organizationId, 'fallback_menu_button_1_label', self::DEFAULTS['fallback_menu_button_1_label']),
            'button_2_label' => $this->getSetting($organizationId, 'fallback_menu_button_2_label', self::DEFAULTS['fallback_menu_button_2_label']),
            'button_3_label' => $this->getSetting($organizationId, 'fallback_menu_button_3_label', self::DEFAULTS['fallback_menu_button_3_label']),
            'button_1_enabled' => (bool) $this->getSetting($organizationId, 'fallback_menu_button_1_enabled', self::DEFAULTS['fallback_menu_button_1_enabled']),
            'button_2_enabled' => (bool) $this->getSetting($organizationId, 'fallback_menu_button_2_enabled', self::DEFAULTS['fallback_menu_button_2_enabled']),
            'button_3_enabled' => $this->getButton3Enabled($organizationId),
        ];
    }

    private function getButton3Enabled(int $organizationId): bool
    {
        $explicit = $this->getSetting($organizationId, 'fallback_menu_button_3_enabled');
        if ($explicit !== null) {
            return (bool) $explicit;
        }
        return (bool) $this->getSetting($organizationId, 'fallback_menu_route_to_agent', self::DEFAULTS['fallback_menu_route_to_agent']);
    }
}