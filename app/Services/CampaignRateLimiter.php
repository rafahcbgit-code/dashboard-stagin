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

        $rate = max(1, min(
            (int) config('campaigns.throttle.default_phone_mps', 70),
            (int) config('campaigns.throttle.max_phone_mps', 80)
        ));

        $capacity = max(1, $rate);
        $key = sprintf(
            'campaign:rate:%d:%s',
            $organizationId,
            preg_replace('/[^A-Za-z0-9:_-]/', '_', (string) ($phoneNumberId ?: 'unknown'))
        );

        while (true) {
            try {
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

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
