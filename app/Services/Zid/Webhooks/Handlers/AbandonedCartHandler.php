<?php

namespace App\Services\Zid\Webhooks\Handlers;

use App\Jobs\Zid\ProcessZidWebhookDeliveryJob;
use App\Models\Contact;
use App\Models\Template;
use App\Models\ZidWebhookDelivery;
use App\Models\ZidWebhookEventSetting;
use App\Services\Outbound\OutboundMessageLogger;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Zid\Webhooks\Exceptions\ZidWebhookNonRetryableException;
use App\Services\Zid\Webhooks\ZidWebhookEventHandlerInterface;
use App\Services\Zid\Webhooks\ZidWebhookMessageSenderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AbandonedCartHandler implements ZidWebhookEventHandlerInterface
{
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly ZidWebhookMessageSenderInterface $messageSender,
        private readonly OutboundMessageLogger $outboundLogger
    ) {
    }

    public function handle(ZidWebhookDelivery $delivery): void
    {
        if (!$delivery->organization_id) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'organization_not_resolved',
            ]);
            return;
        }

        $setting = ZidWebhookEventSetting::with('mappings')
            ->where('organization_id', $delivery->organization_id)
            ->where('event', 'abandoned_cart.created')
            ->first();

        if (!$setting || !$setting->enabled) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'event_disabled',
            ]);
            return;
        }

        if (!$setting->template_id) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'template_not_selected',
            ]);
            return;
        }

        $payload = $delivery->payload ?? [];

        $delayHours = (int) ($setting->send_delay_hours ?? 0);
        if ($delayHours > 0) {
            $sendAt = $this->calculateSendAt($payload, $delayHours);
            if ($sendAt && now()->lt($sendAt)) {
                $delivery->update([
                    'status' => 'scheduled',
                    'scheduled_for' => $sendAt,
                    'processed_at' => null,
                    'error' => null,
                ]);

                ProcessZidWebhookDeliveryJob::dispatch($delivery->id)->delay($sendAt);

                Log::channel('zid')->info('Zid abandoned_cart.created automation: scheduled', [
                    'delivery_id' => $delivery->id,
                    'organization_id' => $delivery->organization_id,
                    'send_at' => $sendAt->toDateTimeString(),
                    'delay_hours' => $delayHours,
                ]);

                return;
            }
        }

        $recipientExpr = $setting->recipient_phone_payload_path ?: 'customer_mobile';

        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? $this->resolvePhoneExpression($payload, 'customer_mobile');

        if (!is_scalar($rawPhone) || trim((string) $rawPhone) === '') {
            throw new ZidWebhookNonRetryableException('recipient_phone_missing');
        }

        $e164 = PhoneService::getE164Format((string) $rawPhone);
        if (!$e164) {
            throw new ZidWebhookNonRetryableException('recipient_phone_invalid');
        }

        $contact = $this->findOrCreateContact((int) $delivery->organization_id, $e164, $payload);

        if (isset($contact->is_subscribed) && !$contact->is_subscribed) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'contact_unsubscribed',
            ]);
            return;
        }

        $template = Template::where('id', $setting->template_id)
            ->where('organization_id', $delivery->organization_id)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            throw new ZidWebhookNonRetryableException('template_not_found');
        }

        [$headerMeta, $bodyMeta, $buttonsMeta] = $this->parameterBuilder->build($template, $setting->mappings, $payload);

        $requestPayload = [
            'to' => $contact->phone ?? null,
            'template' => $template->uuid ?? null,
            'header' => $headerMeta,
            'body' => $bodyMeta,
            'buttons' => $buttonsMeta,
        ];

        [$outboundMessage, $attemptNo] = $this->outboundLogger->beginZidTemplateSend(
            $delivery,
            'abandoned_cart.created',
            $contact,
            $template,
            ['send_delay_hours' => (int) ($setting->send_delay_hours ?? 0)]
        );

        try {
            $response = $this->messageSender->sendTemplateMessage(
                (int) $delivery->organization_id,
                $contact,
                $template,
                $headerMeta,
                $bodyMeta,
                $buttonsMeta
            );
        } catch (\Throwable $e) {
            $this->outboundLogger->logFailure($outboundMessage, $attemptNo, $requestPayload, null, $e->getMessage());
            throw $e;
        }

        if (!data_get($response, 'success')) {
            $message = (string) (data_get($response, 'message')
                ?? data_get($response, 'data.error.message')
                ?? 'send_failed');

            $this->outboundLogger->logFailure($outboundMessage, $attemptNo, $requestPayload, $response, $message);
            throw new \RuntimeException($message);
        }

        $sentChatId = data_get($response, 'data.chat.id');
        $this->outboundLogger->logSuccess(
            $outboundMessage,
            $attemptNo,
            $requestPayload,
            $response,
            is_numeric($sentChatId) ? (int) $sentChatId : null
        );

        $delivery->update([
            'status' => 'processed',
            'processed_at' => now(),
            'sent_chat_id' => is_numeric($sentChatId) ? (int) $sentChatId : null,
        ]);

        Log::channel('zid')->info('Zid abandoned_cart.created automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sent_chat_id' => $delivery->sent_chat_id,
        ]);
    }

    private function calculateSendAt(array $payload, int $delayHours): ?Carbon
    {
        $createdAt = data_get($payload, 'created_at');
        if (is_string($createdAt) && trim($createdAt) !== '') {
            try {
                return Carbon::parse($createdAt)->addHours($delayHours);
            } catch (\Throwable) {
                // fallback to now-based delay.
            }
        }

        if (is_numeric(data_get($payload, 'age_in_minutes'))) {
            $ageMinutes = (int) data_get($payload, 'age_in_minutes');
            $remainingSeconds = max(0, ($delayHours * 3600) - ($ageMinutes * 60));
            return now()->addSeconds($remainingSeconds);
        }

        return now()->addHours($delayHours);
    }

    private function findOrCreateContact(int $organizationId, string $phoneE164, array $payload): Contact
    {
        $existing = Contact::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('phone', $phoneE164)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $fullName = data_get($payload, 'customer_name')
            ?? data_get($payload, 'customer_full_name')
            ?? data_get($payload, 'customer.name')
            ?? $phoneE164;

        $fullName = is_scalar($fullName) ? trim((string) $fullName) : $phoneE164;
        $nameParts = $fullName !== '' ? explode(' ', $fullName, 2) : [];
        $firstName = $nameParts[0] ?? $phoneE164;
        $lastName = $nameParts[1] ?? '';

        return Contact::create([
            'organization_id' => $organizationId,
            'phone' => $phoneE164,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolvePhoneExpression(array $payload, ?string $expression): ?string
    {
        $expression = is_string($expression) ? trim($expression) : '';
        if ($expression === '') {
            return null;
        }

        if (!str_contains($expression, '+')) {
            $value = data_get($payload, $expression);
            return is_scalar($value) ? (string) $value : null;
        }

        $parts = array_values(array_filter(array_map('trim', explode('+', $expression))));
        if (empty($parts)) {
            return null;
        }

        $out = '';
        foreach ($parts as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value)) {
                $out .= (string) $value;
            }
        }

        $out = trim($out);
        return $out === '' ? null : $out;
    }
}

