<?php

namespace App\Services\Zid\Webhooks;

use App\Models\Contact;
use App\Models\Template;

interface ZidWebhookMessageSenderInterface
{
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
