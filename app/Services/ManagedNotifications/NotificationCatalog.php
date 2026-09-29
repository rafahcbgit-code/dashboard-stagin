<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationEmailSender;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationCatalog
{
    private const SAMPLE_VALUES = [
        'organization_name' => 'Example Organization',
        'template_name' => 'example_template',
        'old_status' => 'PENDING',
        'new_status' => 'APPROVED',
        'old_limit' => '250',
        'new_limit' => '1000',
        'phone_number' => '+1 555 0100',
        'waba_id' => '1234567890',
        'ban_state' => 'FLAGGED',
        'old_quality' => 'GREEN',
        'new_quality' => 'YELLOW',
        'requested_name' => 'Example Brand',
        'trial_end_date' => '2026-10-10',
        'subscription_end_date' => '2026-10-10',
        'days_remaining' => '3',
        'plan_name' => 'Business',
        'old_plan_name' => 'Starter',
        'new_plan_name' => 'Business',
        'resource_name' => 'Template messages',
        'usage' => '90',
        'limit' => '100',
        'threshold' => '90',
    ];

    public function all(): array
    {
        return config('managed_notifications.types', []);
    }

    public function get(string $typeKey): array
    {
        return $this->all()[$typeKey]
            ?? throw new InvalidArgumentException("Unsupported notification type: {$typeKey}");
    }

    public function sampleValues(string $typeKey): array
    {
        $values = [];
        foreach ($this->get($typeKey)['variables'] as $variable) {
            $values[$variable] = self::SAMPLE_VALUES[$variable]
                ?? throw new InvalidArgumentException("No safe sample value is defined for {$variable}.");
        }

        return $values;
    }

    public function approvedSenders(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($email) => strtolower(trim((string) $email)),
            array_merge($this->configuredSenders(), NotificationEmailSender::query()->pluck('email')->all())
        ), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    public function isConfiguredSender(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->configuredSenders(), true);
    }

    private function configuredSenders(): array
    {
        return array_values(array_filter(array_map(
            fn ($email) => strtolower(trim((string) $email)),
            array_merge([config('mail.from.address')], config('managed_notifications.allowed_senders', []))
        ), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    public function ensureDefinitions(): void
    {
        $defaultSender = $this->approvedSenders()[0] ?? null;
        $rows = [];
        foreach ($this->all() as $key => $type) {
            $rows[] = [
                'type_key' => $key,
                'enabled' => false,
                'delivery_mode' => $type['delivery_mode'] ?? 'dashboard',
                'dashboard_template' => $type['dashboard'],
                'email_subject' => $type['subject'],
                'email_body' => $type['email'],
                'sender_email' => $defaultSender,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('notification_definitions')->insertOrIgnore($rows);
        }
    }
}
