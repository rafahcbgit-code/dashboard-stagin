<?php

namespace App\Services\Salla\Webhooks;

use App\Models\Contact;
use App\Models\Template;

interface SallaWebhookMessageSenderInterface
{
    /**
     * Send a WhatsApp template message based on resolved parameters.
     *
     * @return object Response object returned by ChatService/WhatsappService
     */
    public function sendTemplateMessage(
        int $organizationId,
        Contact $contact,
        Template $template,
        ?array $headerMeta,
        array $bodyMeta,
        array $buttonsMeta,
        ?string $billingSource = null
    ): object;
}
