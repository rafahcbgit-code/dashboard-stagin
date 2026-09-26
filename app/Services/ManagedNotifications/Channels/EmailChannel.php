<?php

namespace App\Services\ManagedNotifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Services\ManagedNotifications\PermanentDeliveryException;
use App\Services\ManagedNotifications\NotificationCatalog;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    public function __construct(private readonly NotificationCatalog $catalog)
    {
    }

    public function send(NotificationDelivery $delivery, NotificationEvent $event): void
    {
        $rendered = $event->rendered ?? [];
        $sender = strtolower((string) ($rendered['sender'] ?? ''));
        $recipient = (string) $delivery->destination;

        $this->sendRaw($sender, $recipient, (string) ($rendered['subject'] ?? 'Notification'),
            (string) ($rendered['email'] ?? ''));
    }

    public function sendRaw(string $sender, string $recipient, string $subject, string $body): void
    {
        if (! in_array($sender, $this->catalog->approvedSenders(), true)
            || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new PermanentDeliveryException('Invalid notification sender or recipient.');
        }

        Mail::raw($body, function ($message) use ($sender, $recipient, $subject) {
            $message->from($sender)
                ->to($recipient)
                ->subject($subject);
        });
    }
}
