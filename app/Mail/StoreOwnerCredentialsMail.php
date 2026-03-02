<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StoreOwnerCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $email,
        public readonly string $plainPassword,
        public readonly string $storeName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to the Platform – Your Account Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.store-owner-credentials',
        );
    }
}
