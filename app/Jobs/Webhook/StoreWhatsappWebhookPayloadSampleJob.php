<?php

namespace App\Jobs\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreWhatsappWebhookPayloadSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $organizationId,
        public array $payload
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            $summary = $this->summarizeWhatsappWebhookPayload($this->payload);
            $payloadHash = hash('sha256', json_encode($this->payload));

            $inserted = DB::table('whatsapp_webhook_payload_samples')->insertOrIgnore([
                'organization_id' => $this->organizationId,
                'webhook_type' => $summary['webhook_type'],
                'entry_count' => $summary['entry_count'],
                'changes_count' => $summary['changes_count'],
                'message_count' => $summary['message_count'],
                'status_count' => $summary['status_count'],
                'echo_count' => $summary['echo_count'],
                'phone_number_id' => $summary['phone_number_id'],
                'wam_ids' => json_encode($summary['wam_ids']),
                'payload_hash' => $payloadHash,
                'payload' => json_encode($this->redactSensitiveWebhookPayload($this->payload)),
                'received_at' => now(),
            ]);

            if (! $inserted) {
                return;
            }

            Log::channel('webhook')->info('Multi-object WhatsApp webhook payload sample stored', [
                'organization_id' => $this->organizationId,
                'webhook_type' => $summary['webhook_type'],
                'entry_count' => $summary['entry_count'],
                'changes_count' => $summary['changes_count'],
                'message_count' => $summary['message_count'],
                'status_count' => $summary['status_count'],
                'echo_count' => $summary['echo_count'],
                'payload_hash' => $payloadHash,
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to store multi-object WhatsApp webhook payload sample', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function summarizeWhatsappWebhookPayload(array $payload): array
    {
        $entries = $payload['entry'] ?? [];
        $entryCount = is_array($entries) ? count($entries) : 0;
        $changesCount = 0;
        $messageCount = 0;
        $statusCount = 0;
        $echoCount = 0;
        $phoneNumberId = null;
        $wamIds = [];

        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            $changesCount += count($changes);

            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $phoneNumberId = $phoneNumberId ?: ($value['metadata']['phone_number_id'] ?? null);

                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
                $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
                $messageEchoes = is_array($value['message_echoes'] ?? null) ? $value['message_echoes'] : [];

                $messageCount += count($messages);
                $statusCount += count($statuses);
                $echoCount += count($messageEchoes);

                foreach (array_merge($messages, $statuses, $messageEchoes) as $item) {
                    if (! empty($item['id'])) {
                        $wamIds[] = $item['id'];
                    }
                }
            }
        }

        return [
            'webhook_type' => $entries[0]['changes'][0]['field'] ?? 'unknown',
            'entry_count' => $entryCount,
            'changes_count' => $changesCount,
            'message_count' => $messageCount,
            'status_count' => $statusCount,
            'echo_count' => $echoCount,
            'phone_number_id' => $phoneNumberId,
            'wam_ids' => array_values(array_unique(array_slice($wamIds, 0, 100))),
        ];
    }

    protected function redactSensitiveWebhookPayload($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveWebhookPayloadKey($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = $this->redactSensitiveWebhookPayload($item);
        }

        return $redacted;
    }

    protected function isSensitiveWebhookPayloadKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, ['authorization', 'access_token', 'app_secret', 'client_secret'], true)
            || $normalized === 'token'
            || str_ends_with($normalized, '_token');
    }
}
