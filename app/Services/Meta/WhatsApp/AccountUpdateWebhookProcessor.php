<?php

namespace App\Services\Meta\WhatsApp;

use App\Jobs\SyncWhatsappNumberStatusJob;
use App\Models\Organization;
use App\Services\ManagedNotifications\NotificationEventRecorder;
use Illuminate\Support\Facades\DB;

class AccountUpdateWebhookProcessor
{
    public function __construct(private readonly NotificationEventRecorder $events)
    {
    }

    public function process(int $organizationId, string $field, array $change, array $context): void
    {
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $wabaId = (string) ($context['waba_id'] ?? '');
        if ($wabaId === '') {
            return;
        }

        DB::transaction(function () use ($organizationId, $field, $value, $wabaId) {
            $organization = Organization::query()->lockForUpdate()->find($organizationId);
            if (! $organization) {
                return;
            }

            $metadata = json_decode($organization->metadata ?: '{}', true);
            $metadata = is_array($metadata) ? $metadata : [];
            $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
            if ((string) ($whatsapp['waba_id'] ?? '') !== $wabaId) {
                return;
            }

            if (in_array($field, ['phone_number_name_update', 'phone_number_quality_update'], true)) {
                $receivedPhone = preg_replace('/\D/', '', (string) ($value['display_phone_number'] ?? ''));
                $storedPhone = preg_replace('/\D/', '', (string) ($whatsapp['display_phone_number'] ?? ''));
                if ($receivedPhone === '' || $storedPhone === '' || $receivedPhone !== $storedPhone) {
                    return;
                }
            }

            $type = null;
            $variables = [];
            $old = null;
            $new = null;

            switch ($field) {
                case 'account_update':
                    if (($value['event'] ?? null) !== 'DISABLED_UPDATE') {
                        return;
                    }
                    $new = strtoupper((string) data_get($value, 'ban_info.waba_ban_state'));
                    if (! in_array($new, ['FLAGGED', 'DISABLE', 'REINSTATE'], true)) {
                        return;
                    }
                    $old = (string) ($whatsapp['waba_ban_state'] ?? '');
                    $whatsapp['waba_ban_state'] = $new;
                    if ($new !== 'REINSTATE') {
                        $type = 'meta.waba_disabled_flagged';
                        $variables = [
                            'organization_name' => $organization->name,
                            'waba_id' => $wabaId,
                            'ban_state' => $new,
                        ];
                    }
                    break;

                case 'account_review_update':
                    $new = strtoupper((string) ($value['decision'] ?? ''));
                    if (! in_array($new, ['APPROVED', 'REJECTED'], true)) {
                        return;
                    }
                    $old = (string) ($whatsapp['account_review_status'] ?? '');
                    $whatsapp['account_review_status'] = $new;
                    $type = $new === 'REJECTED' ? 'meta.waba_review_rejected' : null;
                    $variables = ['organization_name' => $organization->name, 'waba_id' => $wabaId];
                    break;

                case 'phone_number_name_update':
                    $decision = strtoupper((string) ($value['decision'] ?? ''));
                    $requestedName = (string) ($value['requested_verified_name'] ?? '');
                    if (! in_array($decision, ['APPROVED', 'REJECTED'], true) || $requestedName === '') {
                        return;
                    }
                    $new = $decision.':'.$requestedName;
                    $old = (string) ($whatsapp['phone_name_decision'] ?? '');
                    $whatsapp['phone_name_decision'] = $new;
                    $type = $decision === 'REJECTED' ? 'meta.phone_display_name_rejected' : null;
                    $variables = ['organization_name' => $organization->name, 'phone_number' => (string) $value['display_phone_number'], 'requested_name' => $requestedName];
                    break;

                case 'business_capability_update':
                    $new = $value['max_daily_conversations_per_business'] ?? null;
                    if (! is_string($new) && ! is_int($new)) {
                        return;
                    }
                    $new = (string) $new;
                    $old = (string) ($whatsapp['whatsapp_business_manager_messaging_limit'] ?? $whatsapp['messaging_limit_tier'] ?? '');
                    $oldAmount = $this->limitValue($old);
                    $newAmount = $this->limitValue($new);
                    $whatsapp['whatsapp_business_manager_messaging_limit'] = $new;
                    $whatsapp['messaging_limit_tier'] = $new;
                    if ($oldAmount !== null && $newAmount !== null && $oldAmount !== $newAmount) {
                        $type = 'meta.business_capability_changed';
                        $variables = ['organization_name' => $organization->name, 'old_limit' => $old, 'new_limit' => $new];
                    }
                    break;

                case 'phone_number_quality_update':
                    if (($value['event'] ?? null) === 'DOWNGRADE') {
                        SyncWhatsappNumberStatusJob::dispatch($organizationId)
                            ->onConnection(config('queue.default'))->onQueue('whatsapp-number-status')->afterCommit();
                    }
                    return;

                default:
                    return;
            }

            if ($old === $new) {
                return;
            }

            $whatsapp['notification_transition_sequence'] = (int) ($whatsapp['notification_transition_sequence'] ?? 0) + 1;
            $metadata['whatsapp'] = $whatsapp;
            $organization->metadata = json_encode($metadata, JSON_THROW_ON_ERROR);
            $organization->save();

            if ($type !== null && ($field !== 'business_capability_update' || $old !== '')) {
                $this->events->record(
                    $organizationId, $type,
                    'meta-'.$field.':'.$wabaId.':'.$whatsapp['notification_transition_sequence'].':'.$old.':'.$new,
                    $variables
                );
            }
        });
    }

    private function limitValue(string $limit): ?int
    {
        if ($limit === 'UNLIMITED') {
            return PHP_INT_MAX;
        }
        if (preg_match('/^TIER_(\d+)(K|M)?$/', $limit, $matches)) {
            return (int) $matches[1] * match ($matches[2] ?? '') {
                'K' => 1000, 'M' => 1000000, default => 1,
            };
        }

        return ctype_digit($limit) ? (int) $limit : null;
    }
}
