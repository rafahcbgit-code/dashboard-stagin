<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationDefinition;
use App\Models\Organization;
use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\SubscriptionAuditService;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;

class SubscriptionNotificationProjector
{
    private const RESOURCE_NAMES = [
        'template_messages' => 'Template messages',
        'conversations' => 'Conversations',
        'contacts' => 'Contacts',
        'team_members' => 'Team members',
        'organizations' => 'Organizations',
    ];

    public function __construct(
        private readonly NotificationEventRecorder $recorder,
        private readonly SubscriptionUsageService $usage,
    ) {
    }

    public function project(SubscriptionEvent $source): void
    {
        if ($source->source === 'admin_extend') {
            return;
        }
        $organizationId = (int) $source->organization_id;
        $organization = Organization::query()->whereKey($organizationId)->whereNull('deleted_at')->first();
        if (! $organization) {
            return;
        }

        if ($source->event_type === SubscriptionAuditService::ADD_ON_PURCHASED) {
            $this->projectAddon($source, $organization);
            return;
        }

        $subscription = Subscription::currentForOrganization($organizationId);
        if (! $subscription || (int) $subscription->id !== (int) $source->subscription_id) {
            return;
        }

        $new = $source->new_values ?? [];
        $old = $source->old_values ?? [];
        if (($new['status'] ?? null) !== $subscription->status
            || (int) ($new['plan_id'] ?? 0) !== (int) ($subscription->plan_id ?? 0)
            || ($new['valid_until'] ?? null) !== ($subscription->getRawOriginal('valid_until') ?: null)) {
            return;
        }

        $end = $subscription->getRawOriginal('valid_until');
        if (! $end || Carbon::parse($end)->lte(now())) {
            return;
        }

        $base = ['organization_name' => $organization->name];
        $sourceKey = 'subscription-event:'.$source->id;

        if ($subscription->status === 'trial' && (! $old || ($old['status'] ?? null) !== 'trial')) {
            $this->record('subscription.trial_started', $organizationId, $sourceKey, $base + [
                'trial_end_date' => Carbon::parse($end)->toDateString(),
            ]);
            return;
        }

        if ($subscription->status !== 'active' || ! $subscription->plan_id) {
            return;
        }

        $paid = $base + [
            'plan_name' => (string) ($new['plan_name'] ?? $subscription->plan?->name ?? 'Plan'),
            'subscription_end_date' => Carbon::parse($end)->toDateString(),
        ];
        if ($source->source === 'admin_renew') {
            $this->record('subscription.renewed', $organizationId, $sourceKey, $paid);
            return;
        }
        if (! $old) {
            $previous = Subscription::query()->where('organization_id', $organizationId)
                ->where('id', '<', $subscription->id)->orderByDesc('id')->first();
            if ($previous && $previous->status === 'active' && $previous->plan_id) {
                if ((int) $previous->plan_id === (int) $subscription->plan_id) {
                    $this->record('subscription.renewed', $organizationId, $sourceKey, $paid);
                } else {
                    $previousSnapshot = app(SubscriptionAuditService::class)->subscriptionSnapshot($previous);
                    $direction = $this->planDirection($previousSnapshot['resource_limits'], $new['resource_limits'] ?? []);
                    if ($direction) {
                        $this->record('subscription.'.$direction, $organizationId, $sourceKey, $base + [
                            'old_plan_name' => (string) ($previousSnapshot['plan_name'] ?? 'Previous plan'),
                            'new_plan_name' => (string) ($new['plan_name'] ?? 'New plan'),
                        ]);
                    }
                }
                return;
            }

            $this->record('subscription.activated', $organizationId, $sourceKey, $paid);
            return;
        }

        if (($old['status'] ?? null) !== 'active' || ! ($old['plan_id'] ?? null)) {
            $this->record('subscription.activated', $organizationId, $sourceKey, $paid);
            return;
        }

        if ((int) $old['plan_id'] !== (int) $subscription->plan_id) {
            $direction = $this->planDirection($old['resource_limits'] ?? [], $new['resource_limits'] ?? []);
            if ($direction) {
                $this->record('subscription.'.$direction, $organizationId, $sourceKey, $base + [
                    'old_plan_name' => (string) ($old['plan_name'] ?? 'Previous plan'),
                    'new_plan_name' => (string) ($new['plan_name'] ?? 'New plan'),
                ]);
            }
            return;
        }

        $oldEnd = $old['valid_until'] ?? null;
        if ($oldEnd && Carbon::parse($end)->gt(Carbon::parse($oldEnd))) {
            $this->record('subscription.renewed', $organizationId, $sourceKey, $paid);
        }
    }

    private function projectAddon(SubscriptionEvent $source, Organization $organization): void
    {
        $resource = (string) ($source->new_values['resource'] ?? '');
        $quantity = (int) ($source->new_values['quantity'] ?? 0);
        if ($quantity <= 0 || ! isset(self::RESOURCE_NAMES[$resource])) {
            return;
        }

        $addon = ResourceAddon::query()->find($source->resource_addon_id);
        if (! $addon || ($addon->getRawOriginal('valid_until')
            && Carbon::parse($addon->getRawOriginal('valid_until'))->lte(now()))) {
            return;
        }

        $accountId = $this->usage->resourceAccountId((int) $organization->id);
        if ($accountId !== (int) $organization->id) {
            return;
        }
        $subscription = Subscription::currentForOrganization($accountId);
        if (! $subscription || $subscription->status !== 'active' || ! $subscription->plan_id
            || ($source->new_values['subscription_status'] ?? null) !== 'active'
            || (int) ($source->new_values['subscription_id'] ?? 0) !== (int) $subscription->id
            || (int) ($source->new_values['plan_id'] ?? 0) !== (int) $subscription->plan_id
            || ! $subscription->getRawOriginal('valid_until')
            || Carbon::parse($subscription->getRawOriginal('valid_until'))->lte(now())) {
            return;
        }

        $newLimit = (int) ($source->new_values['new_limit'] ?? -1);
        $oldLimit = (int) ($source->new_values['old_limit'] ?? -1);
        if ($newLimit < 0 || $oldLimit < 0 || $newLimit <= $oldLimit) {
            return;
        }

        $account = Organization::find($accountId);
        $this->record('subscription.limit_increased', $accountId, 'subscription-addon:'.$source->id, [
            'organization_name' => $account?->name ?? $organization->name,
            'resource_name' => self::RESOURCE_NAMES[$resource],
            'old_limit' => (string) $oldLimit,
            'new_limit' => (string) $newLimit,
        ]);
    }

    private function planDirection(array $old, array $new): ?string
    {
        if (array_keys($old) !== array_keys($new) || ! $old) {
            return null;
        }

        $increased = false;
        $decreased = false;
        foreach ($old as $resource => $oldLimit) {
            $before = (int) $oldLimit === -1 ? PHP_INT_MAX : (int) $oldLimit;
            $after = (int) $new[$resource] === -1 ? PHP_INT_MAX : (int) $new[$resource];
            $increased = $increased || $after > $before;
            $decreased = $decreased || $after < $before;
        }

        return match (true) {
            $increased && ! $decreased => 'upgraded',
            $decreased && ! $increased => 'downgraded',
            default => null,
        };
    }

    private function record(string $type, int $organizationId, string $sourceKey, array $variables): void
    {
        if (NotificationDefinition::query()->where('type_key', $type)->where('enabled', true)->exists()) {
            $this->recorder->record($organizationId, $type, $sourceKey, $variables);
        }
    }
}
