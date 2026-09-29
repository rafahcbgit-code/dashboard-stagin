<?php

namespace App\Services\ManagedNotifications;

use App\Jobs\NotifySubscriptionPlanLimitIncreasesJob;
use App\Models\NotificationDefinition;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubscriptionNotificationScanner
{
    private const RESOURCES = [
        'template_messages' => 'Template messages',
        'conversations' => 'Conversations',
        'contacts' => 'Contacts',
        'team_members' => 'Team members',
        'organizations' => 'Organizations',
    ];

    public function __construct(
        private readonly NotificationEventRecorder $recorder,
        private readonly SubscriptionNotificationProjector $projector,
        private readonly SubscriptionUsageService $usage,
    ) {
    }

    public function queuePlanChanges(): void
    {
        $retryBefore = now()->subMinutes(15);
        $changes = DB::table('subscription_plan_notification_changes')
            ->whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', $retryBefore))
            ->orderBy('id')->limit(100)->get();

        foreach ($changes as $change) {
            $leased = DB::table('subscription_plan_notification_changes')->where('id', $change->id)
                ->whereNull('processed_at')
                ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', $retryBefore))
                ->update(['queued_at' => now(), 'updated_at' => now()]);
            if (! $leased) {
                continue;
            }

            try {
                NotifySubscriptionPlanLimitIncreasesJob::dispatch(
                    (int) $change->plan_id,
                    json_decode($change->old_metadata, true, 512, JSON_THROW_ON_ERROR),
                    json_decode($change->new_metadata, true, 512, JSON_THROW_ON_ERROR),
                    $change->change_id,
                    (int) $change->id,
                )->onConnection(config('managed_notifications.queue_connection', 'redis'))
                    ->onQueue(config('managed_notifications.queues.resolve', 'default'));
            } catch (Throwable $exception) {
                DB::table('subscription_plan_notification_changes')->where('id', $change->id)
                    ->whereNull('processed_at')->update(['queued_at' => null, 'updated_at' => now()]);
                throw $exception;
            }
        }
    }

    public function projectEvents(): void
    {
        $startId = (int) Setting::where('key', 'subscription_notification_source_start_id')->value('value');
        SubscriptionEvent::query()->where('id', '>', $startId)
            ->whereNull('notification_projected_at')
            ->orderBy('id')->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $source = SubscriptionEvent::query()->lockForUpdate()->find($event->id);
                        if (! $source || $source->notification_projected_at) {
                            return;
                        }
                        $this->projector->project($source);
                        $source->update(['notification_projected_at' => now()]);
                    });
                }
            });
    }

    public function scanDates(): void
    {
        $startedAt = Setting::where('key', 'subscription_notifications_started_at')->value('value');
        if (! $startedAt) {
            return;
        }

        $enabled = NotificationDefinition::query()->where('enabled', true)
            ->whereIn('type_key', [
                'subscription.trial_expiring', 'subscription.expiring',
                'subscription.trial_expired', 'subscription.expired',
            ])->pluck('type_key')->flip();
        if ($enabled->isEmpty()) {
            return;
        }

        $statuses = [];
        if ($enabled->has('subscription.trial_expiring') || $enabled->has('subscription.trial_expired')) {
            $statuses[] = 'trial';
        }
        if ($enabled->has('subscription.expiring') || $enabled->has('subscription.expired')) {
            $statuses[] = 'active';
        }

        Subscription::query()->with('plan')->whereIn('status', $statuses)
            ->whereNotNull('valid_until')
            ->where('valid_until', '>=', $startedAt)
            ->where('valid_until', '<=', now()->addDays(8))
            ->where(fn ($query) => $query->where('valid_until', '>', now())
                ->orWhereNull('expiry_notification_processed_at')
                ->orWhereColumn('expiry_notification_processed_at', '<', 'valid_until'))
            ->latestPerOrganization()
            ->orderBy('id')->chunkById(200, function ($subscriptions) use ($enabled): void {
                $organizations = Organization::query()->whereIn('id', $subscriptions->pluck('organization_id'))
                    ->whereNull('deleted_at')->get(['id', 'name', 'metadata', 'timezone'])->keyBy('id');
                $recordedReminders = $this->recordedReminders($subscriptions, $enabled);
                foreach ($subscriptions as $subscription) {
                    $organization = $organizations->get($subscription->organization_id);
                    if ($organization) {
                        $this->checkDate($subscription, $organization, $enabled, $recordedReminders);
                    }
                }
            });
    }

    private function recordedReminders(Collection $subscriptions, Collection $enabled): Collection
    {
        $types = $enabled->keys()->intersect(['subscription.trial_expiring', 'subscription.expiring']);
        if ($types->isEmpty()) {
            return collect();
        }

        $identities = [];
        foreach ($subscriptions as $subscription) {
            $type = $subscription->status === 'trial' ? 'subscription.trial_expiring' : 'subscription.expiring';
            if (! $types->contains($type)) {
                continue;
            }
            $keys = [];
            foreach ($subscription->status === 'trial' ? [3, 1] : [7, 1] as $days) {
                $keys[] = hash('sha256', $this->reminderKey($subscription, $days));
            }
            $identities[] = [(int) $subscription->organization_id, $type, $keys];
        }
        if ($identities === []) {
            return collect();
        }

        // At most 400 identities per batch; do not load the tenant's event history.
        return NotificationEvent::query()
            ->where(function ($query) use ($identities): void {
                foreach ($identities as [$organizationId, $type, $keys]) {
                    $query->orWhere(fn ($identity) => $identity->where('organization_id', $organizationId)
                        ->where('type_key', $type)->whereIn('event_key', $keys));
                }
            })
            ->pluck('event_key')->flip();
    }

    private function reminderKey(Subscription $subscription, int $days): string
    {
        return 'subscription-reminder:'.$subscription->id.':'.$subscription->getRawOriginal('valid_until').':'.$days;
    }

    private function checkDate(Subscription $subscription, Organization $organization,
        Collection $enabled, Collection $recordedReminders): void
    {
        $end = $subscription->getRawOriginal('valid_until');
        $endAt = Carbon::parse($end, 'UTC');
        $organizationId = (int) $organization->id;
        $type = $subscription->status === 'trial' ? 'trial' : 'subscription';
        if ($type === 'subscription' && ! $subscription->plan_id) {
            return;
        }

        $base = ['organization_name' => $organization->name];
        $dateVariable = $type === 'trial' ? 'trial_end_date' : 'subscription_end_date';
        $timezone = $organization->effectiveTimezone();
        $localEnd = $endAt->copy()->setTimezone($timezone);
        $base[$dateVariable] = $localEnd->toDateString();

        if ($endAt->lte(now())) {
            $key = 'subscription-expiry:'.$subscription->id.':'.$end;
            $variables = $type === 'trial' ? $base : $base + [
                'plan_name' => $subscription->plan?->name ?? 'Plan',
            ];
            $notificationType = $type === 'trial' ? 'subscription.trial_expired' : 'subscription.expired';
            $this->ifCurrent($subscription, function (Subscription $current) use ($endAt, $enabled, $notificationType, $organizationId, $key, $variables): void {
                if ($current->expiry_notification_processed_at
                    && Carbon::parse($current->getRawOriginal('expiry_notification_processed_at'))->gte($endAt)) {
                    return;
                }
                if ($enabled->has($notificationType)) {
                    $this->recorder->record($organizationId, $notificationType, $key, $variables);
                }
                $current->update(['expiry_notification_processed_at' => now()]);
            });

            return;
        }

        $localNow = now()->setTimezone($timezone);
        if ($localNow->hour < 9) {
            return;
        }
        $days = $localNow->copy()->startOfDay()->diffInDays($localEnd->copy()->startOfDay(), false);
        $allowedDays = $type === 'trial' ? [3, 1] : [7, 1];
        if (! in_array($days, $allowedDays, true)) {
            return;
        }

        $notificationType = $type === 'trial' ? 'subscription.trial_expiring' : 'subscription.expiring';
        if (! $enabled->has($notificationType)) {
            return;
        }
        $key = $this->reminderKey($subscription, $days);
        if ($recordedReminders->has(hash('sha256', $key))) {
            return;
        }
        $variables = $base + ['days_remaining' => (string) $days];
        if ($type === 'subscription') {
            $variables['plan_name'] = $subscription->plan?->name ?? 'Plan';
        }
        $this->ifCurrent($subscription, function () use ($organizationId, $notificationType, $key, $variables): void {
            if (! $this->recorder->hasRecorded($organizationId, $notificationType, $key)) {
                $this->recorder->record($organizationId, $notificationType, $key, $variables);
            }
        });
    }

    public function scanUsage(): void
    {
        if (! NotificationDefinition::query()->where('type_key', 'subscription.resource_warning')->where('enabled', true)->exists()) {
            return;
        }
        $startedAt = Setting::where('key', 'subscription_notifications_started_at')->value('value');
        if (! $startedAt) {
            return;
        }

        $checkedAccounts = [];
        Subscription::query()->whereIn('status', ['trial', 'active'])
            ->where('valid_until', '>', now())
            ->latestPerOrganization()
            ->orderBy('id')->chunkById(100, function ($subscriptions) use ($startedAt, &$checkedAccounts): void {
                $representatives = [];
                $accountIds = $this->usage->bulkResourceAccountIds($subscriptions->pluck('organization_id')->all());
                foreach ($accountIds as $organizationId => $accountId) {
                    if (! isset($checkedAccounts[$accountId])) {
                        $representatives[$accountId] ??= $organizationId;
                    }
                }
                if ($representatives === []) {
                    return;
                }
                $summaries = $this->usage->bulkSummaries(array_values($representatives));
                $accounts = [];
                foreach ($summaries as $resources) {
                    foreach ($resources as $resource => $summary) {
                        $accountId = (int) $summary['billing_organization_id'];
                        $accounts[$accountId][$resource] = $summary;
                    }
                }
                $latestIds = Subscription::query()->selectRaw('MAX(id)')
                    ->whereIn('organization_id', array_keys($accounts))->groupBy('organization_id');
                $accountSubscriptions = Subscription::query()->whereIn('id', $latestIds)
                    ->get()->keyBy('organization_id');
                $organizations = Organization::query()->whereIn('id', array_keys($accounts))
                    ->whereNull('deleted_at')->get(['id', 'name'])->keyBy('id');
                foreach ($accounts as $accountId => $resources) {
                    if (isset($checkedAccounts[$accountId])) {
                        continue;
                    }
                    $subscription = $accountSubscriptions->get($accountId);
                    $organization = $organizations->get($accountId);
                    if (! $subscription || ! $organization
                        || ! in_array($subscription->status, ['trial', 'active'], true)
                        || ! $subscription->getRawOriginal('valid_until')
                        || Carbon::parse($subscription->getRawOriginal('valid_until'))->lte(now())) {
                        continue;
                    }
                    $this->ifCurrent($subscription, function ($current) use ($accountId, $organization, $resources, $startedAt, &$checkedAccounts): void {
                        foreach ($resources as $resource => $summary) {
                            if (isset($summary['subscription_id']) && ((int) $summary['subscription_id'] !== (int) $current->id
                                || (int) $summary['period_version'] !== (int) $current->period_version)) {
                                continue;
                            }
                            $this->checkUsage((int) $accountId, $organization->name, $current,
                                $resource, $summary, $startedAt);
                            $checkedAccounts[$accountId] = true;
                        }
                    });
                }
            });
    }

    private function checkUsage(int $accountId, string $name, Subscription $subscription,
        string $resource, array $summary, string $startedAt): void
    {
        $limit = (int) $summary['limit'];
        if ($limit <= 0 || ! isset(self::RESOURCES[$resource])) {
            return;
        }
        $used = (int) ($summary['pool_used'] ?? $summary['used']);
        $percent = 100 * $used / $limit;
        $threshold = 0;
        foreach ([70, 80, 90, 100] as $level) {
            if ($percent >= $level) {
                $threshold = $level;
            }
        }
        $periodStart = $subscription->getRawOriginal('start_date') ?: $subscription->getRawOriginal('created_at');
        $periodKey = $subscription->usagePeriodKey();

        // ifCurrent holds the subscription lock and transaction for all its resources.
        $state = DB::table('subscription_usage_alert_states')
            ->where('organization_id', $accountId)->where('resource', $resource)->lockForUpdate()->first();
        if (! $state) {
            DB::table('subscription_usage_alert_states')->insertOrIgnore([
                'organization_id' => $accountId, 'resource' => $resource,
                'period_key' => $periodKey, 'last_limit' => $limit,
                'highest_threshold' => Carbon::parse($periodStart ?? $startedAt)->lt(Carbon::parse($startedAt))
                    ? $threshold : 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = DB::table('subscription_usage_alert_states')
                ->where('organization_id', $accountId)->where('resource', $resource)->lockForUpdate()->first();
        }
        $highest = (int) $state->highest_threshold;
        if ($state->period_key !== $periodKey) {
            $highest = 0;
        } elseif ((int) $state->last_limit < $limit) {
            $highest = $threshold;
        } elseif ((int) $state->last_limit > $limit) {
            $highest = 0;
        }

        if ($threshold > $highest) {
            $sourceKey = 'subscription-usage:'.$accountId.':'.$resource.':'.$periodKey.':'.$limit.':'.$threshold;
            if (! $this->recorder->hasRecorded($accountId, 'subscription.resource_warning', $sourceKey)) {
                $this->recorder->record($accountId, 'subscription.resource_warning', $sourceKey, [
                    'organization_name' => $name,
                    'resource_name' => self::RESOURCES[$resource],
                    'usage' => (string) $used,
                    'limit' => (string) $limit,
                    'threshold' => (string) $threshold,
                ]);
            }
        }
        if ($state->period_key !== $periodKey || (int) $state->last_limit !== $limit
            || (int) $state->highest_threshold !== max($highest, $threshold)) {
            DB::table('subscription_usage_alert_states')->where('id', $state->id)->update([
                'period_key' => $periodKey, 'last_limit' => $limit,
                'highest_threshold' => max($highest, $threshold), 'updated_at' => now(),
            ]);
        }
    }

    private function ifCurrent(Subscription $candidate, callable $callback): void
    {
        DB::transaction(function () use ($candidate, $callback): void {
            $current = Subscription::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $current
                || $current->getRawOriginal('valid_until') !== $candidate->getRawOriginal('valid_until')
                || $current->status !== $candidate->status
                || (int) ($current->plan_id ?? 0) !== (int) ($candidate->plan_id ?? 0)
                || (int) $current->period_version !== (int) $candidate->period_version
                || (int) Subscription::query()->where('organization_id', $candidate->organization_id)->max('id') !== (int) $candidate->id) {
                return;
            }
            $callback($current);
        });
    }
}
