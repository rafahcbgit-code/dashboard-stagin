<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Models\Contact;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use Illuminate\Support\Facades\Log;

class OrderCreatedHandler implements SallaWebhookEventHandlerInterface
{
    /**
     * Handles Salla `order.created` event.
     *
     * Responsibilities:
     * - Ensure the webhook is scoped to a valid organization (multi-tenant safe).
     * - Check that the organization enabled this automation and selected a template.
     * - Resolve the recipient phone from payload (supports simple dot-path or a concatenation expression).
     * - Create/find the Contact in ChatBerry for that phone.
     * - Build WhatsApp template parameters using the saved mappings (including button params when needed).
     * - Send the WhatsApp template message via the message sender abstraction.
     * - Update the delivery record status and store sent chat id for traceability.
     */
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly SallaWebhookMessageSenderInterface $messageSender
    ) {
    }

    public function handle(SallaWebhookDelivery $delivery): void
    {
        // 1) Ensure the webhook delivery is associated with an organization.
        //    If merchant→organization mapping failed, we cannot safely proceed.
        if (!$delivery->organization_id) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error' => 'organization_not_resolved',
            ]);
            return;
        }

        // 2) Load automation settings + mappings for this org and event.
        $setting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $delivery->organization_id)
            ->where('event', 'order.created')
            ->first();

        // 3) If the event is disabled, skip silently (no message).
        if (!$setting || !$setting->enabled) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'event_disabled',
            ]);
            return;
        }

        // 4) A template must be selected to send anything.
        if (!$setting->template_id) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'template_not_selected',
            ]);
            return;
        }

        $payload = $delivery->payload ?? [];

        // 5) Resolve recipient phone.
        //    We support two formats:
        //    - Simple dot path: "data.shipping.receiver.phone"
        //    - Concatenation expression: "data.customer.mobile_code+data.customer.mobile"
        //      (useful when country code is stored separately).
        $recipientExpr = $setting->recipient_phone_payload_path ?: 'data.customer.mobile_code+data.customer.mobile';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? $this->resolvePhoneExpression($payload, 'data.customer.mobile_code+data.customer.mobile');

        if (!is_scalar($rawPhone) || trim((string) $rawPhone) === '') {
            // Misconfiguration or payload mismatch: no recipient phone found.
            throw new SallaWebhookNonRetryableException('recipient_phone_missing');
        }

        // 6) Normalize to E.164 format for WhatsApp.
        $e164 = PhoneService::getE164Format((string) $rawPhone);
        if (!$e164) {
            throw new SallaWebhookNonRetryableException('recipient_phone_invalid');
        }

        // 7) Ensure we have a Contact record for this recipient.
        $contact = $this->findOrCreateContact((int) $delivery->organization_id, $e164, $payload);

        // 8) Respect opt-out (if contact unsubscribed, skip sending).
        if (isset($contact->is_subscribed) && !$contact->is_subscribed) {
            $delivery->update([
                'status' => 'skipped',
                'processed_at' => now(),
                'error' => 'contact_unsubscribed',
            ]);
            return;
        }

        // 9) Load the selected template scoped to the same organization.
        $template = \App\Models\Template::where('id', $setting->template_id)
            ->where('organization_id', $delivery->organization_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            throw new SallaWebhookNonRetryableException('template_not_found');
        }

        // 10) Build template parameters from mappings:
        //     - header/body parameters are passed as "static" values to ChatService->buildTemplate
        //     - button URL / copy_code parameters are also built to satisfy WhatsApp API requirements
        //       (otherwise Meta returns 131008: missing button param).
        [$headerMeta, $bodyMeta, $buttonsMeta] = $this->parameterBuilder->build($template, $setting->mappings, $payload);

        // 11) Send via message sender abstraction (currently uses ChatService).
        $response = $this->messageSender->sendTemplateMessage(
            (int) $delivery->organization_id,
            $contact,
            $template,
            $headerMeta,
            $bodyMeta,
            $buttonsMeta
        );

        if (!data_get($response, 'success')) {
            // WhatsApp API failures can be transient; we throw a RuntimeException so the job may retry.
            $message = (string) (data_get($response, 'message')
                ?? data_get($response, 'data.error.message')
                ?? 'send_failed');

            throw new \RuntimeException($message);
        }

        $sentChatId = data_get($response, 'data.chat.id');

        // 12) Mark delivery as processed and store chat id for traceability.
        $delivery->update([
            'status' => 'processed',
            'processed_at' => now(),
            'sent_chat_id' => is_numeric($sentChatId) ? (int) $sentChatId : null,
        ]);

        Log::info('Salla order.created automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sent_chat_id' => $delivery->sent_chat_id,
        ]);
    }

    private function findOrCreateContact(int $organizationId, string $phoneE164, array $payload): Contact
    {
        // Find by (organization_id, phone) to avoid cross-tenant collisions.
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

        // Customer name varies by payload shape. In our sample payload we use data.customer.full_name.
        $fullName = data_get($payload, 'data.customer.full_name');

        $fullName = is_scalar($fullName) ? trim((string) $fullName) : '';
        $nameParts = $fullName !== '' ? explode(' ', $fullName, 2) : [];
        $firstName = $nameParts[0] ?? $phoneE164;
        $lastName = $nameParts[1] ?? '';

        // Create a system-owned contact (created_by = 0).
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
        // Supports:
        // - dot path: "data.shipping.receiver.phone"
        // - concatenation: "data.customer.mobile_code+data.customer.mobile"
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
