<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionPeriodService
{
    public function timezone(Organization $organization): string
    {
        $metadata = json_decode((string) $organization->metadata, true) ?: [];
        $timezone = $metadata['timezone'] ?? $organization->timezone ?? 'UTC';
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    public function manage(string $uuid, string $action, array $input, int $adminId): void
    {
        DB::transaction(function () use ($uuid, $action, $input, $adminId): void {
            $organization = Organization::query()->where('uuid', $uuid)->whereNull('deleted_at')
                ->lockForUpdate()->firstOrFail();
            $subscription = Subscription::query()->where('organization_id', $organization->id)
                ->orderByDesc('id')->lockForUpdate()->first();
            $usage = new SubscriptionUsageService();
            if (! $subscription) {
                $this->invalid('This organization has no subscription.');
            }
            if ($usage->resourceAccountId((int) $organization->id) !== (int) $organization->id) {
                $this->invalid('Manage the subscription of the resource account for this organization.');
            }
            $end = Carbon::parse($input['valid_until'], $this->timezone($organization))->utc();
            $existing = DB::table('subscription_period_archives')->where('organization_id', $organization->id)
                ->where('operation_key', $input['operation_key'])->first();
            if ($existing) {
                if ($action !== 'renew' || $existing->renewed_until !== $end->toDateTimeString()) {
                    $this->invalid('This operation key has already been used.');
                }
                return;
            }
            if ((int) $subscription->id !== (int) $input['subscription_id']
                || (int) $subscription->period_version !== (int) $input['version']) {
                $this->invalid('The subscription changed. Reload the page before trying again.');
            }
            if ($end->lte(now())) {
                $this->invalid('Choose an expiration date in the future.');
            }
            if (! in_array($action, ['extend', 'renew'], true)) {
                $this->invalid('Invalid subscription action.');
            }
            $oldEnd = $subscription->getRawOriginal('valid_until');
            if ($action === 'extend' && (! $oldEnd || $end->lte(Carbon::parse($oldEnd)))) {
                $this->invalid('The extension date must be later than the current expiration date.');
            }
            if ($action === 'renew' && (! $subscription->plan_id || $subscription->status === 'trial'
                || ! $subscription->plan || $subscription->plan->deleted_at)) {
                $this->invalid('Select an active paid plan before renewing this subscription.');
            }

            $audit = app(SubscriptionAuditService::class);
            $old = $audit->subscriptionSnapshot($subscription);
            $attributes = [
                'valid_until' => $end->toDateTimeString(),
                'period_version' => (int) $subscription->period_version + 1,
                'expiry_notification_processed_at' => null,
            ];
            $archiveId = null;
            if ($action === 'renew') {
                $summaries = $usage->bulkSummaries([(int) $organization->id])[(int) $organization->id];
                $snapshot = [];
                foreach ($summaries as $resource => $summary) {
                    $snapshot[$resource] = array_intersect_key($summary,
                        array_flip(['used', 'pool_used', 'limit', 'base_limit', 'addon_quantity']));
                }
                $usageEndId = (int) DB::table('subscription_usage_events')->max('id');
                $conversationEndId = (int) DB::table('conversation_windows')->max('id');
                $archiveId = DB::table('subscription_period_archives')->insertGetId([
                    'organization_id' => $organization->id, 'subscription_id' => $subscription->id,
                    'period_version' => $subscription->period_version, 'operation_key' => $input['operation_key'],
                    'plan_snapshot' => json_encode($old, JSON_THROW_ON_ERROR),
                    'usage_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'started_at' => $subscription->getRawOriginal('start_date'),
                    'scheduled_end_at' => $oldEnd, 'closed_at' => now(), 'renewed_until' => $end,
                    'usage_event_start_id' => $subscription->usage_event_start_id,
                    'usage_event_end_id' => $usageEndId,
                    'conversation_start_id' => $subscription->conversation_start_id,
                    'conversation_end_id' => $conversationEndId,
                    'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $attributes += ['start_date' => now(), 'status' => 'active',
                    'usage_event_start_id' => $usageEndId, 'conversation_start_id' => $conversationEndId];
            }
            Subscription::withoutEvents(fn () => $subscription->update($attributes));
            $audit->record($action === 'renew' ? SubscriptionAuditService::SUBSCRIPTION_RENEWED
                : SubscriptionAuditService::SUBSCRIPTION_EXPIRATION_CHANGED, (int) $organization->id, [
                    'subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id,
                    'source' => 'admin_'.$action, 'actor_id' => $adminId, 'actor_guard' => 'admin',
                    'old_values' => $old, 'new_values' => $audit->subscriptionSnapshot($subscription),
                    'metadata' => ['action' => $action, 'archive_id' => $archiveId],
                    'idempotency_key' => $input['operation_key'],
                ]);
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['valid_until' => __($message)]);
    }
}
