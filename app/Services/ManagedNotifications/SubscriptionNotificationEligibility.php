<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionNotificationEligibility
{
    public function __construct(private readonly SubscriptionUsageService $usage)
    {
    }

    public function isCurrent(NotificationEvent $event): bool
    {
        if ($event->type_key === 'subscription.resource_warning') {
            return $this->isCurrentResourceWarning($event);
        }
        $types = [
            'subscription.trial_expiring' => ['trial', false],
            'subscription.expiring' => ['active', false],
            'subscription.trial_expired' => ['trial', true],
            'subscription.expired' => ['active', true],
        ];
        if (! isset($types[$event->type_key])) {
            return true;
        }

        [$status, $expired] = $types[$event->type_key];
        $subscription = $this->currentSubscription($event);
        $end = $subscription?->getRawOriginal('valid_until');
        if (! $subscription || $subscription->status !== $status || ! $end
            || ($status === 'active' && ! $subscription->plan_id)) {
            return false;
        }

        $endAt = Carbon::parse($end, 'UTC');
        if ($expired) {
            return $endAt->lte(now()) && hash_equals($event->event_key,
                hash('sha256', 'subscription-expiry:'.$subscription->id.':'.$end));
        }

        $days = (int) ($event->variables['days_remaining'] ?? 0);
        if ($endAt->lte(now()) || ! in_array($days, $status === 'trial' ? [3, 1] : [7, 1], true)
            || ! hash_equals($event->event_key,
                hash('sha256', 'subscription-reminder:'.$subscription->id.':'.$end.':'.$days))) {
            return false;
        }

        $organization = Organization::query()->find($event->organization_id, ['id', 'metadata', 'timezone']);
        if (! $organization) {
            return false;
        }
        $timezone = $organization->effectiveTimezone();
        $localNow = now()->setTimezone($timezone);

        return $localNow->hour >= 9 && $localNow->startOfDay()->diffInDays(
            $endAt->setTimezone($timezone)->startOfDay(), false) === $days;
    }

    private function isCurrentResourceWarning(NotificationEvent $event): bool
    {
        $subscription = $this->currentSubscription($event);
        $end = $subscription?->getRawOriginal('valid_until');
        $threshold = (int) ($event->variables['threshold'] ?? 0);
        $limit = (int) ($event->variables['limit'] ?? 0);
        if (! $subscription || ! in_array($subscription->status, ['active', 'trial'], true)
            || ($subscription->status === 'active' && ! $subscription->plan_id)
            || ! $end || Carbon::parse($end, 'UTC')->lte(now()) || $limit <= 0
            || ! in_array($threshold, [70, 80, 90, 100], true)) {
            return false;
        }

        $periodKey = $subscription->usagePeriodKey();
        $states = DB::table('subscription_usage_alert_states')->where('organization_id', $event->organization_id)
            ->where('period_key', $periodKey)->where('last_limit', $limit)->get(['resource']);
        foreach ($states as $state) {
            $key = 'subscription-usage:'.$event->organization_id.':'.$state->resource.':'.$periodKey.':'.$limit.':'.$threshold;
            if (hash_equals($event->event_key, hash('sha256', $key))) {
                // Recheck only the allowance, without counting the organization's usage.
                return $this->usage->effectiveLimit($subscription, $state->resource) === $limit;
            }
        }

        return false;
    }

    private function currentSubscription(NotificationEvent $event): ?Subscription
    {
        // Called within resolution/claim transactions; shared locks also read current committed state.
        return Subscription::query()->where('organization_id', $event->organization_id)
            ->orderByDesc('id')->sharedLock()->first();
    }
}
