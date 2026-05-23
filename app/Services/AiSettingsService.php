<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Setting;

class AiSettingsService
{
    const DEFAULTS = [
        'ai_enabled' => false,
        'ai_only_mode' => false,
        'default_confidence_threshold' => 85,
    ];

    public function get(int $organizationId): array
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return self::DEFAULTS;
        }

        $metadata = $org->metadata ? json_decode($org->metadata, true) : [];
        $aiSettings = $metadata['ai_settings'] ?? [];

        return array_merge(self::DEFAULTS, $aiSettings);
    }

    public function update(int $organizationId, array $settings): array
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return self::DEFAULTS;
        }

        $metadata = $org->metadata ? json_decode($org->metadata, true) : [];

        $aiSettings = array_merge(
            self::DEFAULTS,
            $metadata['ai_settings'] ?? [],
            $settings
        );

        $metadata['ai_settings'] = $aiSettings;
        $org->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $org->save();

        return $aiSettings;
    }

    public function isEnabled(int $organizationId): bool
    {
        return (bool) ($this->get($organizationId)['ai_enabled'] ?? false);
    }

    public function isAiOnlyMode(int $organizationId): bool
    {
        return (bool) ($this->get($organizationId)['ai_only_mode'] ?? false);
    }

    /**
     * Get the AI confidence threshold.
     * Priority: global admin setting → org-level setting → hardcoded default.
     */
    public function getDefaultConfidence(int $organizationId): int
    {
        // 1. Global admin setting (highest priority)
        $globalValue = Setting::getValueByKey('ai_confidence_threshold');
        if ($globalValue !== null && $globalValue !== '') {
            return (int) $globalValue;
        }

        // 2. Org-level setting from ai_settings
        $orgValue = $this->get($organizationId)['default_confidence_threshold'] ?? null;
        if ($orgValue !== null && $orgValue !== '') {
            return (int) $orgValue;
        }

        // 3. Hardcoded default
        return self::DEFAULTS['default_confidence_threshold'];
    }
}