<?php

namespace App\Services\ManagedNotifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSender;
use App\Models\NotificationEvent;
use App\Services\ManagedNotifications\PermanentDeliveryException;
use App\Services\ManagedNotifications\NotificationCatalog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailer;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

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

        $this->sendMessage($sender, $recipient, (string) ($rendered['subject'] ?? 'Notification'),
            (string) ($rendered['email'] ?? ''), $rendered['email_html'] ?? null);
    }

    public function sendMessage(string $sender, string $recipient, string $subject, string $body, ?string $html = null): void
    {
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL)
            || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new PermanentDeliveryException('Invalid notification sender or recipient.');
        }

        $account = NotificationEmailSender::query()->where('email', $sender)->first();
        if (! $account && ! $this->catalog->isConfiguredSender($sender)) {
            throw new PermanentDeliveryException('Invalid notification sender or recipient.');
        }
        $send = function ($message) use ($sender, $recipient, $subject) {
            $message->from($sender)
                ->to($recipient)
                ->subject($subject);
        };

        if (! $account) {
            if ($html === null) {
                Mail::raw($body, $send);
            } else {
                Mail::send(['html' => new HtmlString($html), 'raw' => $body], [], $send);
            }
            return;
        }

        try {
            $mailer = new Mailer(
                'notification-smtp',
                app('view'),
                app('mail.manager')->createSymfonyTransport([
                    'transport' => 'smtp',
                    'host' => $account->host,
                    'port' => $account->port,
                    'encryption' => $account->encryption,
                    'scheme' => $account->encryption === 'ssl' ? 'smtps' : 'smtp',
                    'username' => $account->username,
                    'password' => $account->password,
                    'timeout' => 10,
                ]),
                app('events')
            );
            if ($html === null) {
                $mailer->raw($body, $send);
            } else {
                $mailer->send(['html' => new HtmlString($html), 'raw' => $body], [], $send);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Notification SMTP transport failed.', 0, $exception);
        }
    }
}
