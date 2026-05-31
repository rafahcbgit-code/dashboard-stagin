<?php 
// app/Helpers/Email.php

namespace App\Helpers;

use App\Mail\CustomEmail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Email
{
    public static function log($userId, $recipient, $subject, $body, $status, $attempts, $metadata = null)
    {
        EmailLog::create([
            'user_id' => $userId,
            'recipient' => $recipient,
            'subject' => $subject,
            'message' => $body,
            'status' => $status,
            'attempts' => $attempts,
            'metadata' => $metadata,
        ]);
    }

    public static function sendInvite($template, $recipientEmail, $inviter, $link)
    {
        $smtpQuery = Setting::where('key', 'smtp_email_active')->first();

        if($smtpQuery->value != 1){
            Log::warning("Email skipped: smtp_email_active is not enabled (value={$smtpQuery->value})", [
                'template' => $template,
                'recipient' => $recipientEmail,
            ]);
            self::log($inviter->id ?? null, $recipientEmail, $template, '', 'skipped', 0, json_encode(['reason' => 'smtp_email_active_disabled']));
            return;
        }

        $emailTemplate = EmailTemplate::where('name', $template)->first();

        if(!$emailTemplate){
            Log::error("Email template not found: {$template}", ['recipient' => $recipientEmail]);
            self::log($inviter->id ?? null, $recipientEmail, $template, '', 'failed', 0, json_encode(['reason' => 'template_not_found']));
            return;
        }

        $subject = self::replacePlaceholders($emailTemplate->subject, $inviter, $link);
        $body = self::replacePlaceholders($emailTemplate->body, $inviter, $link);

        try {
            Mail::to($recipientEmail)->queue(new CustomEmail($subject, $body));
            Log::info("Invite email queued", [
                'template' => $template,
                'recipient' => $recipientEmail,
                'inviter' => $inviter->id ?? null,
                'link' => $link,
            ]);
            self::log($inviter->id ?? null, $recipientEmail, $subject, $body, 'queued', 1, json_encode(['template' => $template, 'link' => $link]));
        } catch (\Exception $e) {
            Log::error("Invite email failed: " . $e->getMessage(), [
                'template' => $template,
                'recipient' => $recipientEmail,
                'exception' => get_class($e),
            ]);
            self::log($inviter->id ?? null, $recipientEmail, $subject, $body, 'failed', 1, json_encode(['reason' => $e->getMessage(), 'template' => $template]));
        }
    }

    public static function sendPasswordReset($template, $recipient, $link)
    {
        $smtpQuery = Setting::where('key', 'smtp_email_active')->first();

        if($smtpQuery->value != 1){
            Log::warning("Email skipped: smtp_email_active is not enabled", ['template' => $template, 'recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'skipped', 0, json_encode(['reason' => 'smtp_email_active_disabled']));
            return;
        }

        $emailTemplate = EmailTemplate::where('name', $template)->first();

        if(!$emailTemplate){
            Log::error("Email template not found: {$template}", ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'failed', 0, json_encode(['reason' => 'template_not_found']));
            return;
        }

        $subject = self::replacePlaceholders($emailTemplate->subject, $recipient, $link);
        $body = self::replacePlaceholders($emailTemplate->body, $recipient, $link);

        try {
            Mail::to($recipient->email)->queue(new CustomEmail($subject, $body));
            Log::info("Password reset email queued", ['recipient' => $recipient->email]);
            self::log($recipient->id ?? null, $recipient->email, $subject, $body, 'queued', 1, json_encode(['template' => $template]));
        } catch (\Exception $e) {
            Log::error("Password reset email failed: " . $e->getMessage(), ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $subject, $body, 'failed', 1, json_encode(['reason' => $e->getMessage()]));
        }
    }

    public static function sendSubscriptionEmail($template, $recipient, $plan)
    {
        $smtpQuery = Setting::where('key', 'smtp_email_active')->first();

        if($smtpQuery->value != 1){
            Log::warning("Email skipped: smtp_email_active is not enabled", ['template' => $template, 'recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'skipped', 0, json_encode(['reason' => 'smtp_email_active_disabled']));
            return;
        }

        $emailTemplate = EmailTemplate::where('name', $template)->first();

        if(!$emailTemplate){
            Log::error("Email template not found: {$template}", ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'failed', 0, json_encode(['reason' => 'template_not_found']));
            return;
        }

        $subject = self::replacePlaceholders($emailTemplate->subject, $recipient, NULL, $plan);
        $body = self::replacePlaceholders($emailTemplate->body, $recipient, NULL, $plan);

        try {
            Mail::to($recipient->email)->queue(new CustomEmail($subject, $body));
            Log::info("Subscription email queued", ['template' => $template, 'recipient' => $recipient->email]);
            self::log($recipient->id ?? null, $recipient->email, $subject, $body, 'queued', 1, json_encode(['template' => $template, 'plan' => $plan->name ?? null]));
        } catch (\Exception $e) {
            Log::error("Subscription email failed: " . $e->getMessage(), ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $subject, $body, 'failed', 1, json_encode(['reason' => $e->getMessage()]));
        }
    }

    public static function send($template, $recipient)
    {
        $smtpQuery = Setting::where('key', 'smtp_email_active')->first();

        if($smtpQuery->value != 1){
            Log::warning("Email skipped: smtp_email_active is not enabled", ['template' => $template, 'recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'skipped', 0, json_encode(['reason' => 'smtp_email_active_disabled']));
            return;
        }

        $emailTemplate = EmailTemplate::where('name', $template)->first();

        if(!$emailTemplate){
            Log::error("Email template not found: {$template}", ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $template, '', 'failed', 0, json_encode(['reason' => 'template_not_found']));
            return;
        }

        $subject = self::replacePlaceholders($emailTemplate->subject, $recipient);
        $body = self::replacePlaceholders($emailTemplate->body, $recipient);

        try {
            Mail::to($recipient->email)->queue(new CustomEmail($subject, $body));
            Log::info("Email queued: {$template}", ['recipient' => $recipient->email]);
            self::log($recipient->id ?? null, $recipient->email, $subject, $body, 'queued', 1, json_encode(['template' => $template]));
        } catch (\Exception $e) {
            Log::error("Email failed ({$template}): " . $e->getMessage(), ['recipient' => $recipient->email ?? null]);
            self::log($recipient->id ?? null, $recipient->email ?? null, $subject, $body, 'failed', 1, json_encode(['reason' => $e->getMessage(), 'template' => $template]));
        }
    }

    private static function replacePlaceholders($content, $user = NULL, $link = NULL, $plan = NULL)
    {
        // Define the replacements
        $replacements = [
            '{{FirstName}}' => $user->first_name ?? NULL,
            '{{LastName}}' => $user->last_name ?? NULL,
            '{{Email}}' => $user->email ?? NULL,
            '{{FullName}}' => $user->full_name ?? NULL,
            '{{Link}}' => $link,
            '{{plan}}' => $plan->name ?? NULL,
            '{{CompanyName}}' => Setting::where('key', 'company_name')->value('value'),
        ];

        // Replace the placeholders
        $replacedContent = Str::replace(array_keys($replacements), array_values($replacements), $content);

        return $replacedContent;
    }
}

