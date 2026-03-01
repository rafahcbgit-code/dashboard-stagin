<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Models\Contact;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Outbound\OutboundMessageLogger;
use Illuminate\Support\Facades\Log;

class CustomerOtpRequestHandler implements SallaWebhookEventHandlerInterface
{
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly SallaWebhookMessageSenderInterface $messageSender,
        private readonly OutboundMessageLogger $outboundLogger
    ) {
    }

    public function handle(SallaWebhookDelivery $delivery): void
    {
        if (!$delivery->organization_id) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'organization_not_resolved',
            ]);
            return;
        }

        $setting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $delivery->organization_id)
            ->where('event', 'customer.otp.request')
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
        $recipientExpr = $setting->recipient_phone_payload_path ?: 'data.contact';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr);

        if (!is_scalar($rawPhone) || trim((string) $rawPhone) === '') {
            throw new SallaWebhookNonRetryableException('recipient_phone_missing');
        }

        $e164 = PhoneService::getE164Format((string) $rawPhone);
        if (!$e164) {
            throw new SallaWebhookNonRetryableException('recipient_phone_invalid');
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

        $template = \App\Models\Template::where('id', $setting->template_id)
            ->where('organization_id', $delivery->organization_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            throw new SallaWebhookNonRetryableException('template_not_found');
        }

        [$headerMeta, $bodyMeta, $buttonsMeta] = $this->parameterBuilder->build($template, $setting->mappings, $payload);

        $requestPayload = [
            'to' => $contact->phone ?? null,
            'template' => $template->uuid ?? null,
            'header' => $headerMeta,
            'body' => $bodyMeta,
            'buttons' => $buttonsMeta,
        ];

        [$outboundMessage, $attemptNo] = $this->outboundLogger->beginSallaTemplateSend(
            $delivery,
            'customer.otp.request',
            $contact,
            $template
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

        Log::info('Salla customer.otp.request automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sent_chat_id' => $delivery->sent_chat_id,
        ]);
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

        $label = data_get($payload, 'data.contact') ?? $phoneE164;
        $label = is_scalar($label) ? trim((string) $label) : '';
        $firstName = $label !== '' ? $label : $phoneE164;
        $lastName = '';

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
        foreach ($parts as $part) {
            $value = data_get($payload, $part);
            if (is_scalar($value)) {
                $out .= (string) $value;
            }
        }

        $out = trim($out);
        return $out === '' ? null : $out;
    }
}
