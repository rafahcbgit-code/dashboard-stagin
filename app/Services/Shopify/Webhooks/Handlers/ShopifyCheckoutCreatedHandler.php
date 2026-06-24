<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Models\Contact;
use App\Models\Template;
use App\Models\ShopifyWebhookDelivery;
use App\Models\ShopifyWebhookEventSetting;
use App\Services\Outbound\OutboundMessageLogger;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use App\Services\Shopify\Webhooks\ShopifyWebhookEventHandlerInterface;
use Illuminate\Support\Facades\Log;

class ShopifyCheckoutCreatedHandler implements ShopifyWebhookEventHandlerInterface
{
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly SallaWebhookMessageSenderInterface $messageSender,
        private readonly OutboundMessageLogger $outboundLogger
    ) {
    }

    public function handle(ShopifyWebhookDelivery $delivery): void
    {
        if (!$delivery->organization_id) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'organization_not_resolved',
            ]);
            return;
        }

        $setting = ShopifyWebhookEventSetting::with('mappings')
            ->where('organization_id', $delivery->organization_id)
            ->where('event', 'checkouts/create')
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
        $recipientExpr = $setting->recipient_phone_payload_path ?? 'phone';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? $this->resolvePhoneExpression($payload, 'customer.phone')
            ?? $this->resolvePhoneExpression($payload, 'phone');

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

        [$outboundMessage, $attemptNo] = $this->outboundLogger->beginShopifyTemplateSend(
            $delivery,
            'checkouts.create',
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
                $buttonsMeta,
                'shopify'
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

        Log::info('Shopify checkouts/create automation: message sent', [
            'delivery_id' => $delivery->id,
            'shopify_store_id' => $delivery->shopify_store_id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
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

        $firstName = data_get($payload, 'customer.first_name') ?? data_get($payload, 'shipping_address.first_name');
        $lastName = data_get($payload, 'customer.last_name') ?? data_get($payload, 'shipping_address.last_name');
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
