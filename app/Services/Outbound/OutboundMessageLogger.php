<?php

namespace App\Services\Outbound;

use App\Models\Contact;
use App\Models\OutboundMessage;
use App\Models\OutboundMessageAttempt;
use App\Models\SallaWebhookDelivery;
use App\Models\Template;
use Illuminate\Support\Arr;

class OutboundMessageLogger
{
    /**
     * Create or load an OutboundMessage row for a Salla-triggered automation.
     *
     * Returns [OutboundMessage, attemptNo].
     */
    public function beginSallaTemplateSend(
        SallaWebhookDelivery $delivery,
        string $automationKey,
        Contact $contact,
        Template $template,
        array $context = []
    ): array {
        $message = OutboundMessage::updateOrCreate(
            [
                'organization_id' => (int) $delivery->organization_id,
                'source' => 'salla',
                'source_delivery_id' => $delivery->id,
                'automation_key' => $automationKey,
            ],
            [
                'source_event' => $delivery->event,
                'contact_id' => $contact->id,
                'recipient_e164' => $contact->phone ?? null,
                'template_id' => $template->id,
                'template_name' => $template->name ?? null,
                'template_uuid' => $template->uuid ?? null,
                'channel' => 'whatsapp',
                'provider' => 'chat_service',
                'status' => 'processing',
                'context' => $context ?: null,
                'last_error_message' => null,
            ]
        );

        // Reserve an attempt number by incrementing the counter.
        $message->increment('attempts');
        $message->refresh();

        return [$message, (int) ($message->attempts ?? 1)];
    }

    public function logSuccess(
        OutboundMessage $message,
        int $attemptNo,
        array $requestPayload,
        object $response,
        ?int $sentChatId = null
    ): void {
        $resp = $this->toArray($response);

        OutboundMessageAttempt::create([
            'outbound_message_id' => $message->id,
            'attempt_no' => $attemptNo,
            'result' => 'success',
            'http_status' => Arr::get($resp, 'http_status'),
            'provider_message_id' => Arr::get($resp, 'data.message_id'),
            'duration_ms' => null,
            'request_payload' => $requestPayload,
            'response_payload' => $resp,
            'error_message' => null,
        ]);

        $message->update([
            'status' => 'sent',
            'sent_chat_id' => $sentChatId,
            'sent_at' => now(),
            'last_error_message' => null,
        ]);
    }

    public function logFailure(
        OutboundMessage $message,
        int $attemptNo,
        array $requestPayload,
        ?object $response,
        string $errorMessage
    ): void {
        $resp = $response ? $this->toArray($response) : null;

        OutboundMessageAttempt::create([
            'outbound_message_id' => $message->id,
            'attempt_no' => $attemptNo,
            'result' => 'fail',
            'http_status' => $resp ? Arr::get($resp, 'http_status') : null,
            'provider_message_id' => $resp ? Arr::get($resp, 'data.message_id') : null,
            'duration_ms' => null,
            'request_payload' => $requestPayload,
            'response_payload' => $resp,
            'error_message' => $errorMessage,
        ]);

        $message->update([
            'status' => 'failed',
            'last_error_message' => $errorMessage,
        ]);
    }

    private function toArray(object $obj): array
    {
        return json_decode(json_encode($obj, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}

