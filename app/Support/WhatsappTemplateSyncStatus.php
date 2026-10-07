<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class WhatsappTemplateSyncStatus
{
    private const TTL_SECONDS = 86400;

    public static function cacheKey(int $organizationId, string $wabaId): string
    {
        return 'whatsapp-template-sync:' . $organizationId . ':' . hash('sha256', $wabaId);
    }

    public static function update(int $organizationId, string $wabaId, string $status, array $details = []): void
    {
        Cache::put(self::cacheKey($organizationId, $wabaId), array_merge([
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ], $details), self::TTL_SECONDS);
    }

    public static function get(int $organizationId, string $wabaId): array
    {
        return Cache::get(self::cacheKey($organizationId, $wabaId), ['status' => 'idle']);
    }
}
