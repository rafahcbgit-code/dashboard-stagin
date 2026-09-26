<?php

namespace App\Services\ManagedNotifications;

use InvalidArgumentException;

class WebhookUrlValidator
{
    public function validate(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', config('managed_notifications.webhook_allowed_hosts', []));

        if (mb_strlen($url) > 2048
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || ! in_array($host, $allowed, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new InvalidArgumentException('Webhook URL must use HTTPS and an operator-approved host.');
        }
    }

    public function validateResolvedAddress(string $url): string
    {
        $this->validate($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

        if ($records === []) {
            throw new InvalidArgumentException('Webhook host could not be resolved.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Webhook host resolves to a restricted address.');
            }
            $addresses[] = $ip;
        }

        return $addresses[0];
    }
}
