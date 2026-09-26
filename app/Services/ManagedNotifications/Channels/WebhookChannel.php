<?php

namespace App\Services\ManagedNotifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Services\ManagedNotifications\PermanentDeliveryException;
use App\Services\ManagedNotifications\WebhookUrlValidator;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

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

        if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
            throw new PermanentDeliveryException('Webhook rejected request with HTTP '.$response->status());
        }

        throw new RuntimeException('Webhook returned HTTP '.$response->status());
    }
}
