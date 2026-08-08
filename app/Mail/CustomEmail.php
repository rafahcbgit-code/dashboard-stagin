<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CustomEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * SMTP can take several seconds. Keep these messages out of the default
     * queue so they cannot delay webhooks, imports, or other short jobs.
     */
    public $subject;
    public $body;

    public function __construct($subject, $body)
    {
        $this->onQueue('emails');
        $this->subject = $subject;
        $this->body = $body;
    }

    public function build()
    {
        return $this->subject($this->subject)
                    ->view('emails.custom_email_template')
                    ->with(['body' => $this->body]);
    }
}
