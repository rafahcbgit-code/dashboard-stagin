<?php

namespace App\Services\Zid\Webhooks;

use App\Models\Contact;
use App\Models\Template;
use App\Services\ChatService;

class ChatServiceTemplateMessageSender implements ZidWebhookMessageSenderInterface
{
    public function sendTemplateMessage(
        int $organizationId,
        Contact $contact,
        Template $template,
        ?array $headerMeta,
        array $bodyMeta,
        array $buttonsMeta
    ): object {
        $chatService = new ChatService($organizationId);

        return $chatService->sendTemplateMessage((object) [
            'template' => $template->uuid,
            'header' => $headerMeta,
            'body' => $bodyMeta,
            'buttons' => $buttonsMeta,
            'footer' => ['text' => null],
            'user_id' => 0,
        ], $contact->uuid);
    }
}
