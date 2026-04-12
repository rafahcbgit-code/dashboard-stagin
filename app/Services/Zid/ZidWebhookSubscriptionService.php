<?php

namespace App\Services\Zid;

use App\Models\IntegrationConnection;
use App\Models\ZidWebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZidWebhookSubscriptionService
{
    protected array $events;
    protected array $conditionKeys;
    protected string $targetUrl;

    public function __construct()
    {
        $this->events = config('zid.webhook_events', []);
        $this->conditionKeys = config('zid.webhook_condition_keys', []);
        $this->targetUrl = config('zid.webhook_target');

    }

    public function subscribeEvent(IntegrationConnection $connection, string $event, array $conditions = []): ZidWebhookSubscription
    {
        if (! $this->targetUrl) {
            throw new \RuntimeException('Zid webhook target URL is not configured.');
        }

        $meta = $this->events[$event] ?? null;
        if (! $meta) {
            throw new \InvalidArgumentException('Unsupported Zid webhook event: ' . $event);
        }

        $authorizationToken = $connection->authorization_token;
        $managerToken = $connection->manager_token;

        if (!$authorizationToken || !$managerToken) {
            throw new \RuntimeException('Missing Zid tokens for subscription.');
        }

        $originalId = $this->buildOriginalId($connection->store_id, $event);

        $payload = [
            'event' => $event,
            'target_url' => $this->targetUrl,
            'original_id' => $originalId,
        ];

        if ($meta['supports_conditions']) {
            $filtered = $this->filterValidConditions($conditions);
            if (!empty($filtered)) {
                $payload['conditions'] = $filtered;
            }
        } else {
            $filtered = [];
        }

        $response = $this->callCreate($authorizationToken, $managerToken, $payload);

        return ZidWebhookSubscription::updateOrCreate(
            [
                'store_id' => $connection->store_id,
                'event_name' => $event,
            ],
            [
                'organization_id' => $connection->organization_id,
                //'user_id' => $connection->user_id,
                'platform' => config('zid.platform'),
                'webhook_id' => data_get($response, 'id'),
                'original_id' => $originalId,
                'target_url' => $this->targetUrl,
                'conditions_json' => $filtered ?: null,
                'status' => 'active',
            ]
        );
    }

    public function unsubscribeEvent(ZidWebhookSubscription $subscription): void
    {
        $connection = IntegrationConnection::where('store_id', $subscription->store_id)
            ->where('platform', config('zid.platform'))
            ->first();

        if (! $connection || ! $connection->authorization_token || ! $connection->manager_token) {
            $subscription->update(['status' => 'inactive']);
            return;
        }

        $this->callDelete($connection->authorization_token, $connection->manager_token, $subscription->original_id);
        $subscription->update(['status' => 'inactive']);
    }

    protected function filterValidConditions(array $conditions): array
    {
        if (empty($conditions)) {
            return [];
        }

        return collect($conditions)
            ->filter(fn ($value, $key) => in_array($key, $this->conditionKeys, true) && $value !== null && $value !== '')
            ->toArray();
    }

    protected function callCreate(string $authorizationToken, string $managerToken, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authorizationToken,
                'X-Manager-Token' => $managerToken,
            ])->timeout(20)
                ->post('https://api.zid.sa/v1/managers/webhooks', $payload);
        } catch (\Throwable $exception) {
            Log::channel('zid')->error('Zid webhook creation failed (network)', [
                'event' => $payload['event'] ?? null,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        if (! $response->successful()) {
            Log::channel('zid')->error('Zid webhook creation failed (API response)', [
                'status' => $response->status(),
                'body' => $response->body(),
                'event' => $payload['event'] ?? null,
            ]);
            throw new \RuntimeException('Zid webhook creation failed for event ' . ($payload['event'] ?? 'unknown'));
        }

        return $response->json() ?? [];
    }

    protected function callDelete(string $authorizationToken, string $managerToken, string $originalId): void
    {
        $url = 'https://api.zid.sa/v1/managers/webhooks?original_id=' . urlencode($originalId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authorizationToken,
                'X-Manager-Token' => $managerToken,
            ])->timeout(20)
                ->delete($url);
        } catch (\Throwable $exception) {
            Log::channel('zid')->error('Zid webhook deletion failed (network)', [
                'original_id' => $originalId,
                'message' => $exception->getMessage(),
            ]);
            return;
        }

        if (! $response->successful()) {
            Log::channel('zid')->warning('Zid webhook deletion returned non-success status', [
                'status' => $response->status(),
                'body' => $response->body(),
                'original_id' => $originalId,
            ]);
        }
    }

    protected function buildOriginalId(string $storeId, string $event): string
    {
        return $storeId . '::' . $event;
    }
}

