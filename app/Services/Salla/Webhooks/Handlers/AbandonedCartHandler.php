<?php

namespace App\Services\Salla\Webhooks\Handlers;

use App\Jobs\Salla\ProcessSallaWebhookDeliveryJob;
use App\Models\Contact;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use App\Services\Salla\Webhooks\SallaWebhookEventHandlerInterface;
use App\Services\Salla\Webhooks\SallaWebhookMessageSenderInterface;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AbandonedCartHandler implements SallaWebhookEventHandlerInterface
{
    /**
     * Handles Salla `abandoned.cart` event.
     *
     * This automation is similar to `order.created`:
     * - Per-organization enable/disable + selected template
     * - Variable mapping from webhook payload to WhatsApp template parameters
     */
    public function __construct(
        private readonly TemplateParameterBuilder $parameterBuilder,
        private readonly SallaWebhookMessageSenderInterface $messageSender
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
            ->where('event', 'abandoned.cart')
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

        // Optional: schedule sending after N hours (used for abandoned cart reminders).
        // We anchor the delay to the cart creation timestamp in the payload (if available).
        $delayHours = (int) ($setting->send_delay_hours ?? 0);
        if ($delayHours > 0) {
            $sendAt = $this->calculateSendAt($payload, $delayHours);
            if ($sendAt && now()->lt($sendAt)) {
                // Mark delivery as scheduled for observability, then re-dispatch processing at the due time.
                $delivery->update([
                    'status' => 'scheduled',
                    'scheduled_for' => $sendAt,
                    'processed_at' => null,
                    'error' => null,
                ]);

                ProcessSallaWebhookDeliveryJob::dispatch($delivery->id)->delay($sendAt);

                Log::info('Salla abandoned.cart automation: scheduled', [
                    'delivery_id' => $delivery->id,
                    'organization_id' => $delivery->organization_id,
                    'send_at' => $sendAt->toDateTimeString(),
                    'delay_hours' => $delayHours,
                ]);

                return;
            }
        }

        $recipientExpr = $setting->recipient_phone_payload_path ?: 'data.customer.mobile';
        $rawPhone = $this->resolvePhoneExpression($payload, $recipientExpr)
            ?? $this->resolvePhoneExpression($payload, 'data.customer.mobile');

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

        $response = $this->messageSender->sendTemplateMessage(
            (int) $delivery->organization_id,
            $contact,
            $template,
            $headerMeta,
            $bodyMeta,
            $buttonsMeta
        );

        if (!data_get($response, 'success')) {
            $message = (string) (data_get($response, 'message')
                ?? data_get($response, 'data.error.message')
                ?? 'send_failed');

            throw new \RuntimeException($message);
        }

        $sentChatId = data_get($response, 'data.chat.id');

        $delivery->update([
            'status' => 'processed',
            'processed_at' => now(),
            'sent_chat_id' => is_numeric($sentChatId) ? (int) $sentChatId : null,
        ]);

        Log::info('Salla abandoned.cart automation: message sent', [
            'delivery_id' => $delivery->id,
            'organization_id' => $delivery->organization_id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sent_chat_id' => $delivery->sent_chat_id,
        ]);
    }

    private function calculateSendAt(array $payload, int $delayHours): ?Carbon
    {
        // Prefer the cart creation timestamp if present.
        $createdAt = data_get($payload, 'data.created_at.date');
        if (is_string($createdAt) && trim($createdAt) !== '') {
            try {
                return Carbon::parse($createdAt)->addHours($delayHours);
            } catch (\Throwable) {
                // Fallback to age_in_minutes if parsing fails.
            }
        }

        // Fallback: compute remaining delay using age_in_minutes (if provided).
        $ageMinutes = data_get($payload, 'data.age_in_minutes');
        if (is_numeric($ageMinutes)) {
            $remainingSeconds = max(0, ($delayHours * 3600) - ((int) $ageMinutes * 60));
            return now()->addSeconds($remainingSeconds);
        }

        // Last resort: delay from "now".
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

        $fullName = data_get($payload, 'data.customer.name')
            ?? data_get($payload, 'data.customer.full_name');

        $fullName = is_scalar($fullName) ? trim((string) $fullName) : '';
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
