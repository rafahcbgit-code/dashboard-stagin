<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyCodeMail extends Mailable
{
    public $code;
    public $user;

    public function __construct($user, $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    public function build()
    {
        return $this->subject('رمز التحقق')
                    ->html('
                        <p>مرحباً ' . e($this->user->name) . '</p>
                        <p>رمز التحقق هو:</p>
                        <h2>' . $this->code . '</h2>
                    ');
    }
}

