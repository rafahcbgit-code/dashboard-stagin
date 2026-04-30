<?php

namespace App\Services;

use App\Models\Organization;

class AiSettingsService
{
    const DEFAULTS = [
        'ai_enabled' => false,
        'ai_only_mode' => false,
        'default_confidence_threshold' => 75,
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

    public function getDefaultConfidence(int $organizationId): int
    {
        return (int) ($this->get($organizationId)['default_confidence_threshold'] ?? self::DEFAULTS['default_confidence_threshold']);
    }
}