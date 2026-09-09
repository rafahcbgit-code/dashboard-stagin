<?php

namespace App\Services;

/** Single source of truth for campaign retry configuration. */
class CampaignRetrySettings
{
    public static function enabled(array $metadata): bool
    {
        return (bool) ($metadata['campaigns']['enable_resend'] ?? false);
    }

    public static function intervals(array $metadata): array
    {
        $intervals = $metadata['campaigns']['resend_intervals'] ?? null;

        if (empty($intervals) || ! is_array($intervals)) {
            $intervals = config('campaigns.retry.default_intervals', [5, 15, 60]);
        }

        return array_values(array_filter($intervals, fn ($value) => is_numeric($value) && $value > 0));
    }
}
