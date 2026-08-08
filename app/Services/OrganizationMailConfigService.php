<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\Organization;

class OrganizationMailConfigService
{
    /**
     * Send an assignment notification email to an agent.
     * Uses the organization's SMTP settings if configured and active.
     * Falls back to global SMTP if org settings are not configured.
     */
    public function sendAssignmentEmail(int $organizationId, int $agentId, int $contactId): bool
    {
        $org = Organization::query()
            ->select(['id', 'name'])
            ->find($organizationId);
        if (!$org) {
            Log::warning('OrganizationMailConfigService: Organization not found', ['org_id' => $organizationId]);
            return false;
        }

        $metadata = OrganizationService::getCachedMetadata($organizationId);
        $emailSettings = $metadata['email_settings'] ?? [];

        // Check if email notifications are enabled for this org
        if (empty($emailSettings['active'])) {
            Log::info('OrganizationMailConfigService: Email notifications disabled for org', ['org_id' => $organizationId]);
            return false;
        }

        // Load the agent
        $agent = \App\Models\User::query()
            ->select(['id', 'email'])
            ->find($agentId);
        if (!$agent || empty($agent->email)) {
            Log::warning('OrganizationMailConfigService: Agent not found or no email', ['agent_id' => $agentId]);
            return false;
        }

        // Load the contact
        $contact = \App\Models\Contact::query()
            ->select(['id', 'first_name', 'last_name', 'phone'])
            ->find($contactId);
        $contactName = $contact ? ($contact->full_name ?: $contact->phone ?: 'Unknown') : 'Unknown';

        $fromName = $this->resolveFromName($emailSettings, $org);
        $fromAddress = $this->resolveFromAddress($emailSettings);

        $subject = "New conversation assigned to you";
        $body = "A new conversation from {$contactName} has been assigned to you in {$org->name}.\n\nPlease log in to respond.";

        return $this->sendWithOrgConfig($emailSettings, $agent->email, $subject, $body, $fromName, $fromAddress);
    }

    /**
     * Send a test email using organization SMTP settings.
     */
    public function sendTestEmail(int $organizationId, string $testEmail, ?array $settingsOverride = null): array
    {
        $org = Organization::query()
            ->select(['id', 'name'])
            ->find($organizationId);
        if (!$org) {
            return ['success' => false, 'message' => 'Organization not found'];
        }

        $metadata = OrganizationService::getCachedMetadata($organizationId);
        $emailSettings = $metadata['email_settings'] ?? [];
        $emailSettings = $this->mergeEmailSettings($emailSettings, $settingsOverride);

        if (empty($emailSettings['host']) || empty($emailSettings['username'])) {
            return ['success' => false, 'message' => 'SMTP settings are incomplete. Please fill in host and username.'];
        }

        $fromName = $this->resolveFromName($emailSettings, $org);
        $fromAddress = $this->resolveFromAddress($emailSettings);

        $subject = 'Chatberry - Test Email';
        $body = "This is a test email from your Chatberry notification settings.\n\nIf you received this email, your SMTP configuration is working correctly.";

        try {
            $sent = $this->sendWithOrgConfig($emailSettings, $testEmail, $subject, $body, $fromName, $fromAddress);
            if ($sent) {
                return ['success' => true, 'message' => 'Test email sent successfully.'];
            }
            return ['success' => false, 'message' => 'Failed to send test email. Please check your SMTP credentials.'];
        } catch (\Exception $e) {
            Log::error('OrganizationMailConfigService: Test email failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'SMTP connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Send a custom email to an arbitrary recipient using the organization's SMTP settings.
     * Used by MetaFlow post-submission email notifications and similar features.
     */
    public function sendCustomEmail(int $organizationId, string $toEmail, string $subject, string $body): bool
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            Log::warning('OrganizationMailConfigService: Organization not found', ['org_id' => $organizationId]);
            return false;
        }

        $metadata = $org->metadata ? json_decode($org->metadata, true) : [];
        $emailSettings = $metadata['email_settings'] ?? [];

        if (empty($emailSettings['active'])) {
            Log::info('OrganizationMailConfigService: Email notifications disabled for org', ['org_id' => $organizationId]);
            return false;
        }

        $fromName = $this->resolveFromName($emailSettings, $org);
        $fromAddress = $this->resolveFromAddress($emailSettings);

        return $this->sendWithOrgConfig($emailSettings, $toEmail, $subject, $body, $fromName, $fromAddress);
    }

    /**
     * Send one plain text email to multiple recipients using one org SMTP setup.
     */
    public function sendCustomEmailToMany(
        int $organizationId,
        array $toEmails,
        string $subject,
        string $body,
        ?Organization $organization = null,
        ?array $emailSettings = null
    ): int
    {
        $org = $organization ?? Organization::query()
            ->select(['id', 'name'])
            ->find($organizationId);

        if (! $org) {
            Log::warning('OrganizationMailConfigService: Organization not found', ['org_id' => $organizationId]);
            return 0;
        }

        $emailSettings ??= OrganizationService::getCachedMetadata($organizationId)['email_settings'] ?? [];

        if (empty($emailSettings['active'])) {
            Log::info('OrganizationMailConfigService: Email notifications disabled for org', ['org_id' => $organizationId]);
            return 0;
        }

        if (empty($emailSettings['host']) || empty($emailSettings['username'])) {
            Log::warning('OrganizationMailConfigService: SMTP settings incomplete for org', ['org_id' => $organizationId]);
            return 0;
        }

        $emails = collect($toEmails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return 0;
        }

        $fromName = $this->resolveFromName($emailSettings, $org);
        $fromAddress = $this->resolveFromAddress($emailSettings);
        $sentCount = 0;

        try {
            config([
                'mail.mailers.org_smtp.host' => $emailSettings['host'],
                'mail.mailers.org_smtp.port' => $emailSettings['port'] ?? 587,
                'mail.mailers.org_smtp.username' => $emailSettings['username'],
                'mail.mailers.org_smtp.password' => $emailSettings['password'] ?? null,
                'mail.mailers.org_smtp.encryption' => $emailSettings['encryption'] ?? 'tls',
            ]);

            app('mail.manager')->purge('org_smtp');

            // A single SMTP transaction can deliver to multiple BCC recipients.
            // Chunking keeps a rejected address isolated without paying a network
            // round trip for every recipient in the common case.
            foreach ($emails->chunk(50) as $recipientChunk) {
                $recipients = $recipientChunk->all();

                try {
                    Mail::mailer('org_smtp')->raw($body, function ($message) use ($recipients, $subject, $fromName, $fromAddress) {
                        $message->bcc($recipients)
                            ->subject($subject)
                            ->from($fromAddress, $fromName);
                    });

                    $sentCount += count($recipients);
                } catch (\Throwable $e) {
                    Log::warning('OrganizationMailConfigService: Bulk email chunk failed', [
                        'recipient_count' => count($recipients),
                        'subject' => $subject,
                        'error' => $e->getMessage(),
                    ]);

                    // Preserve the previous per-recipient failure isolation only
                    // for a failed chunk, which is an exceptional path.
                    foreach ($recipients as $email) {
                        try {
                            Mail::mailer('org_smtp')->raw($body, function ($message) use ($email, $subject, $fromName, $fromAddress) {
                                $message->to($email)
                                    ->subject($subject)
                                    ->from($fromAddress, $fromName);
                            });

                            $sentCount++;
                        } catch (\Throwable $recipientException) {
                            Log::warning('OrganizationMailConfigService: Bulk email recipient failed', [
                                'to' => $email,
                                'subject' => $subject,
                                'error' => $recipientException->getMessage(),
                            ]);
                        }
                    }
                }
            }
        } finally {
            app('mail.manager')->purge('org_smtp');
        }

        Log::info('OrganizationMailConfigService: Bulk email send completed', [
            'subject' => $subject,
            'total_recipients' => $emails->count(),
            'sent_count' => $sentCount,
        ]);

        return $sentCount;
    }

    /**
     * Send a plain text email using the dedicated 'org_smtp' mailer.
     *
     * Uses a separate named mailer ('org_smtp') so that org-specific SMTP
     * credentials NEVER touch the default 'smtp' mailer used by platform
     * emails (invites, password resets, subscriptions). This prevents
     * credential bleed in shared queue-worker processes.
     */
    private function sendWithOrgConfig(array $emailSettings, string $toEmail, string $subject, string $body, string $fromName, string $fromAddress): bool
    {
        try {
            // Configure the dedicated org_smtp mailer with org-specific credentials
            config([
                'mail.mailers.org_smtp.host' => $emailSettings['host'],
                'mail.mailers.org_smtp.port' => $emailSettings['port'] ?? 587,
                'mail.mailers.org_smtp.username' => $emailSettings['username'],
                'mail.mailers.org_smtp.password' => $emailSettings['password'],
                'mail.mailers.org_smtp.encryption' => $emailSettings['encryption'] ?? 'tls',
            ]);

            // Purge any cached org_smtp transport so the new config takes effect
            app('mail.manager')->purge('org_smtp');

            Mail::mailer('org_smtp')->raw($body, function ($message) use ($toEmail, $subject, $fromName, $fromAddress) {
                $message->to($toEmail)
                    ->subject($subject)
                    ->from($fromAddress, $fromName);
            });

            Log::info('OrganizationMailConfigService: Email sent via org_smtp', [
                'to' => $toEmail,
                'subject' => $subject,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('OrganizationMailConfigService: Email send failed', [
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            // Purge org_smtp transport to close the connection.
            // No need to restore config — the default 'smtp' mailer was never touched.
            app('mail.manager')->purge('org_smtp');
        }
    }

    private function mergeEmailSettings(array $savedSettings, ?array $override): array
    {
        if ($override === null) {
            return $savedSettings;
        }

        $allowedKeys = ['active', 'host', 'port', 'username', 'password', 'encryption', 'from_name', 'from_address'];
        $settings = $savedSettings;

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $override)) {
                $settings[$key] = $override[$key];
            }
        }

        if (empty($settings['password']) && ! empty($savedSettings['password'])) {
            $settings['password'] = $savedSettings['password'];
        }

        return $settings;
    }

    private function resolveFromName(array $emailSettings, Organization $org): string
    {
        return $this->filledString($emailSettings['from_name'] ?? null)
            ?? $this->filledString($org->name)
            ?? 'Chatberry';
    }

    private function resolveFromAddress(array $emailSettings): string
    {
        return $this->filledString($emailSettings['from_address'] ?? null)
            ?? $this->filledString($emailSettings['username'] ?? null)
            ?? config('mail.from.address');
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
