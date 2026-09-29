<?php

namespace App\Services\ManagedNotifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Services\ManagedNotifications\PermanentDeliveryException;
use App\Services\ManagedNotifications\RetryableDeliveryException;
use App\Services\ManagedNotifications\WebhookUrlValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class WebhookChannel
{
    public function __construct(private readonly WebhookUrlValidator $urls)
    {
    }

    public function send(NotificationDelivery $delivery, NotificationEvent $event): void
    {
        $url = (string) $delivery->destination;
        $secret = (string) $delivery->webhook_secret;
        if ($secret === '') {
            throw new PermanentDeliveryException('Webhook signing secret is missing.');
        }

        try {
            $address = $this->urls->validateResolvedAddress($url);
        } catch (InvalidArgumentException $exception) {
            throw new PermanentDeliveryException($exception->getMessage(), previous: $exception);
        }
        if (! defined('CURLOPT_RESOLVE')) {
            throw new PermanentDeliveryException('Webhook delivery requires the cURL transport.');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $pinnedAddress = str_contains($address, ':') ? '['.$address.']' : $address;

        $payload = [
            'event_id' => $event->id,
            'type' => $event->type_key,
            'organization_id' => $event->organization_id,
            'variables' => $event->variables,
            'occurred_at' => $event->created_at?->toIso8601String(),
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = Http::acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinnedAddress}"]],
            ])
            ->withHeaders([
                'X-Chatberry-Event-ID' => (string) $event->id,
                'X-Chatberry-Idempotency-Key' => 'notification-'.$event->id,
                'X-Chatberry-Signature-256' => 'sha256='.hash_hmac('sha256', $json, $secret),
            ])
            ->withBody($json, 'application/json')
            ->post($url);

        if ($response->successful()) {
            return;
        }

        if ($response->status() >= 400 && $response->status() < 500 && ! in_array($response->status(), [408, 429], true)) {
            throw new PermanentDeliveryException('Webhook rejected request with HTTP '.$response->status());
        }

        throw new RetryableDeliveryException('Webhook returned HTTP '.$response->status(),
            $this->retryAfterSeconds($response->header('Retry-After')));
    }

    private function retryAfterSeconds(?string $value): int
    {
        $value = trim((string) $value);
        if (ctype_digit($value)) {
            return min(86400, (int) $value);
        }
        try {
            $date = Carbon::createFromFormat(DATE_RFC7231, $value, 'UTC');

            return $date ? max(0, min(86400, now()->diffInSeconds($date, false))) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
