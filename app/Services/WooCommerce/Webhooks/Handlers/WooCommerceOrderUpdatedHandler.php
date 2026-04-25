<?php

namespace App\Services\WooCommerce\Webhooks\Handlers;

use App\Models\Contact;
use App\Models\Template;
use App\Models\WooCommerceWebhookDelivery;
use App\Models\WooCommerceWebhookEventSetting;
use App\Models\WooCommerceOrderState;
use App\Services\Outbound\OutboundMessageLogger;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use App\Services\WooCommerce\Webhooks\WooCommerceWebhookEventHandlerInterface;
use App\Services\WooCommerce\Webhooks\WooCommerceOrderItemsSummaryFormatter;
use Illuminate\Support\Facades\Log;

class WooCommerceOrderUpdatedHandler implements WooCommerceWebhookEventHandlerInterface
{
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly SallaWebhookMessageSenderInterface $messageSender,
        private readonly OutboundMessageLogger $outboundLogger,
        private readonly WooCommerceOrderItemsSummaryFormatter $orderItemsSummaryFormatter
    ) {
    }

    public function handle(WooCommerceWebhookDelivery $delivery): void
    {
        if (!$delivery->organization_id) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'organization_not_resolved',
            ]);
            return;
        }

        $payload = $delivery->payload ?? [];
        if (!is_scalar(data_get($payload, 'chatberry_order.products_summary'))) {
            $payload['chatberry_order']['products_summary'] = $this->orderItemsSummaryFormatter->build($payload);
        }
        $orderId = data_get($payload, 'id');
        $currentStatus = is_scalar(data_get($payload, 'status'))
            ? mb_strtolower(trim((string) data_get($payload, 'status')))
            : '';

        if (!is_numeric($orderId) || $currentStatus === '') {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'order_or_status_missing',
            ]);
            return;
        }

        $state = WooCommerceOrderState::firstOrNew([
            'organization_id' => (int) $delivery->organization_id,
            'order_id' => (int) $orderId,
        ]);

        $previousStatus = $state->exists ? (string) ($state->last_status ?? '') : '';

        // Baseline first seen status to avoid sending on non-status updates or first unknown update.
        if ($previousStatus === '') {
            $state->integration_id = $delivery->integration_id;
            $state->last_status = $currentStatus;
            $state->last_seen_at = now();
            $state->save();

            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'status_baseline_initialized',
            ]);
            return;
        }

        if ($previousStatus === $currentStatus) {
            $state->last_seen_at = now();
            $state->save();

            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'status_not_changed',
            ]);
            return;
        }

        $setting = WooCommerceWebhookEventSetting::with('mappings')
            ->where('organization_id', $delivery->organization_id)
            ->where('event', $this->eventKeyForStatus($currentStatus))
            ->first();

        if (!$setting || !$setting->enabled) {
            $state->integration_id = $delivery->integration_id;
            $state->last_status = $currentStatus;
            $state->last_seen_at = now();
            $state->save();

            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'status_template_not_configured',
            ]);
            return;
        }

        if (!$setting->template_id) {
            $state->integration_id = $delivery->integration_id;
            $state->last_status = $currentStatus;
            $state->last_seen_at = now();
            $state->save();

            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'template_not_selected',
            ]);
            return;
        }

        $recipientExpr = $setting->recipient_phone_payload_path ?? 'billing.phone';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? $this->resolvePhoneExpression($payload, 'billing.phone')
            ?? $this->resolvePhoneExpression($payload, 'shipping.phone');

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

        $template = Template::where('id', $setting->template_id)
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

        [$outboundMessage, $attemptNo] = $this->outboundLogger->beginWooCommerceTemplateSend(
            $delivery,
            'order.updated.' . $currentStatus,
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
            'error' => null,
        ]);

        $state->integration_id = $delivery->integration_id;
        $state->last_status = $currentStatus;
        $state->last_seen_at = now();
        $state->save();

        Log::channel('woocommerce')->info('WooCommerce order.updated automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'current_status' => $currentStatus,
            'previous_status' => $previousStatus,
        ]);
    }

    private function eventKeyForStatus(string $status): string
    {
        return 'order.updated.status.' . $status;
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

        $firstName = data_get($payload, 'billing.first_name') ?? data_get($payload, 'shipping.first_name');
        $lastName = data_get($payload, 'billing.last_name') ?? data_get($payload, 'shipping.last_name');
        $fullName = trim(($firstName ? (string) $firstName : '') . ' ' . ($lastName ? (string) $lastName : ''));
        if ($fullName === '') {
            $fullName = $phoneE164;
        }

        $nameParts = explode(' ', $fullName, 2);
        $first = $nameParts[0] ?? $phoneE164;
        $last = $nameParts[1] ?? '';

        return Contact::create([
            'organization_id' => $organizationId,
            'phone' => $phoneE164,
            'first_name' => $first,
            'last_name' => $last,
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
