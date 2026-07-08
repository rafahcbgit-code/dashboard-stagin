<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CampaignRateLimiter
{
    private const LUA_SCRIPT = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local capacity = tonumber(ARGV[3])
local cost = tonumber(ARGV[4])

local bucket = redis.call('HMGET', key, 'tokens', 'updated_at')
local tokens = tonumber(bucket[1])
local updated_at = tonumber(bucket[2])

if tokens == nil then
    tokens = capacity
    updated_at = now
end

local elapsed = math.max(0, now - updated_at)
tokens = math.min(capacity, tokens + (elapsed / 1000) * rate)

if tokens >= cost then
    tokens = tokens - cost
    redis.call('HMSET', key, 'tokens', tokens, 'updated_at', now)
    redis.call('PEXPIRE', key, math.ceil((capacity / rate) * 2000))
    return {1, 0, math.floor(tokens * 1000)}
end

local wait_ms = math.ceil(((cost - tokens) / rate) * 1000)
redis.call('HMSET', key, 'tokens', tokens, 'updated_at', now)
redis.call('PEXPIRE', key, math.ceil((capacity / rate) * 2000))
return {0, wait_ms, math.floor(tokens * 1000)}
LUA;

    public static function waitForPhoneCapacity(int $organizationId, ?string $phoneNumberId): void
    {
        if (! config('campaigns.throttle.enabled', true)) {
            return;
        }

        $rate = self::phoneRate($organizationId);

        $capacity = max(1, $rate);
        $key = self::rateKey($organizationId, $phoneNumberId);
        $backoffKey = self::backoffKey($organizationId, $phoneNumberId);

        while (true) {
            try {
                $pausedUntil = (int) (Redis::get($backoffKey) ?: 0);
                $now = self::nowMs();
                if ($pausedUntil > $now) {
                    usleep(min(5000, $pausedUntil - $now) * 1000);
                    continue;
                }

                $result = Redis::eval(self::LUA_SCRIPT, 1, $key, self::nowMs(), $rate, $capacity, 1);
            } catch (\Throwable $e) {
                Log::warning('Campaign rate limiter unavailable; applying local fallback delay', [
                    'organization_id' => $organizationId,
                    'phone_number_id' => $phoneNumberId,
                    'error' => $e->getMessage(),
                ]);

                usleep((int) ceil(1000000 / $rate));
                return;
            }

            $allowed = (int) ($result[0] ?? 0);
            if ($allowed === 1) {
                return;
            }

            $waitMs = max(1, min(1000, (int) ($result[1] ?? 1)));
            usleep($waitMs * 1000);
        }
    }

    public static function pausePhone(int $organizationId, ?string $phoneNumberId, ?int $seconds = null, array $context = []): void
    {
        if (! config('campaigns.throttle.enabled', true)) {
            return;
        }

        $seconds = max(1, $seconds ?? (int) config('campaigns.throttle.rate_limit_backoff_seconds', 120));
        $key = self::backoffKey($organizationId, $phoneNumberId);
        $pausedUntil = self::nowMs() + ($seconds * 1000);

        try {
            $currentPausedUntil = (int) (Redis::get($key) ?: 0);
            if ($currentPausedUntil >= $pausedUntil) {
                return;
            }

            Redis::setex($key, $seconds + 60, (string) $pausedUntil);

            Log::warning('Campaign sending paused after WhatsApp rate limit', array_filter([
                'organization_id' => $organizationId,
                'phone_number_id' => $phoneNumberId,
                'pause_seconds' => $seconds,
                'context' => $context ?: null,
            ], static fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            Log::warning('Unable to record WhatsApp rate-limit backoff', [
                'organization_id' => $organizationId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function phoneRate(int $organizationId): int
    {
        $metadata = OrganizationService::getCachedMetadata($organizationId);
        $orgRate = $metadata['campaigns']['phone_mps'] ?? null;
        $defaultRate = (int) config('campaigns.throttle.default_phone_mps', 20);
        $maxRate = (int) config('campaigns.throttle.max_phone_mps', 80);

        return max(1, min((int) ($orgRate ?? $defaultRate), $maxRate));
    }

    private static function backoffKey(int $organizationId, ?string $phoneNumberId): string
    {
        return self::rateKey($organizationId, $phoneNumberId).':backoff';
    }

    private static function rateKey(int $organizationId, ?string $phoneNumberId): string
    {
        return sprintf(
            'campaign:rate:%s',
            $phoneNumberId
                ? 'phone:'.preg_replace('/[^A-Za-z0-9:_-]/', '_', $phoneNumberId)
                : 'org:'.$organizationId.':unknown'
        );
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
