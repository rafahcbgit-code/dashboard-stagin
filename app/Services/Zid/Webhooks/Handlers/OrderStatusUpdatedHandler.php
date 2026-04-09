<?php

namespace App\Services\Zid\Webhooks\Handlers;

use App\Models\Contact;
use App\Models\Template;
use App\Models\ZidWebhookDelivery;
use App\Models\ZidWebhookEventSetting;
use App\Models\ZidWebhookEventStatusRule;
use App\Services\Outbound\OutboundMessageLogger;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Zid\Webhooks\Exceptions\ZidWebhookNonRetryableException;
use App\Services\Zid\Webhooks\ZidWebhookEventHandlerInterface;
use App\Services\Zid\Webhooks\ZidWebhookMessageSenderInterface;
use Illuminate\Support\Facades\Log;

class OrderStatusUpdatedHandler implements ZidWebhookEventHandlerInterface
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

        $setting = ZidWebhookEventSetting::where('organization_id', $delivery->organization_id)
            ->where('event', 'order.status.update')
            ->first();

        if (!$setting || !$setting->enabled) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'event_disabled',
            ]);
            return;
        }

        $payload = $delivery->payload ?? [];

        $statusSlug = $this->extractStatusSlug($payload);
        if (!$statusSlug) {
            throw new ZidWebhookNonRetryableException('status_slug_missing');
        }

        $rule = ZidWebhookEventStatusRule::with('mappings')
            ->where('zid_webhook_event_setting_id', $setting->id)
            ->where('status_slug', $statusSlug)
            ->first();

        if (!$rule || !$rule->enabled) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'status_not_configured:' . $statusSlug,
            ]);
            return;
        }

        if (!$rule->template_id) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'template_not_selected',
            ]);
            return;
        }

        $recipientExpr = $setting->recipient_phone_payload_path ?: 'customer.mobile';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? data_get($payload, 'customer.mobile')
            ?? data_get($payload, 'customer.phone');

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

        $template = Template::where('id', $rule->template_id)
            ->where('organization_id', $delivery->organization_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            throw new ZidWebhookNonRetryableException('template_not_found');
        }

        [$headerMeta, $bodyMeta, $buttonsMeta] = $this->parameterBuilder->build($template, $rule->mappings, $payload);

        $requestPayload = [
            'to' => $contact->phone ?? null,
            'template' => $template->uuid ?? null,
            'header' => $headerMeta,
            'body' => $bodyMeta,
            'buttons' => $buttonsMeta,
        ];

        [$outboundMessage, $attemptNo] = $this->outboundLogger->beginZidTemplateSend(
            $delivery,
            'order.status.update:' . $statusSlug,
            $contact,
            $template,
            ['status_slug' => $statusSlug, 'rule_id' => $rule->id]
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

        Log::info('Zid order.status.update automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'status_slug' => $statusSlug,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sent_chat_id' => $delivery->sent_chat_id,
        ]);
    }

    private function extractStatusSlug(array $payload): ?string
    {
        $slug = data_get($payload, 'order_status.code')
            ?? data_get($payload, 'order_status.name')
            ?? data_get($payload, 'order.status.code')
            ?? data_get($payload, 'order.status.name');

        return is_scalar($slug) ? (string) $slug : null;
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

        $fullName = data_get($payload, 'customer.name')
            ?? data_get($payload, 'customer.full_name')
            ?? data_get($payload, 'customer.first_name')
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
