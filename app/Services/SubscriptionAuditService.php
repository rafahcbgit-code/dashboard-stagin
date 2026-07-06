<?php

namespace App\Services;

use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SubscriptionAuditService
{
    public const SUBSCRIPTION_CREATED = 'subscription_created';
    public const SUBSCRIPTION_ACTIVATED = 'subscription_activated';
    public const SUBSCRIPTION_RENEWED = 'subscription_renewed';
    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const SUBSCRIPTION_EXPIRATION_CHANGED = 'subscription_expiration_changed';
    public const PLAN_ASSIGNED = 'plan_assigned';
    public const PLAN_CHANGED = 'plan_changed';
    public const PLAN_UPGRADED = 'plan_upgraded';
    public const PLAN_DOWNGRADED = 'plan_downgraded';
    public const ADD_ON_PURCHASED = 'add_on_purchased';
    public const ADD_ON_EXPIRED = 'add_on_expired';
    public const ADD_ON_DELETED = 'add_on_deleted';
    public const TRIAL_STARTED = 'trial_started';
    public const TRIAL_ENDED = 'trial_ended';

    private const RELEVANT_SUBSCRIPTION_FIELDS = [
        'plan_id',
        'status',
        'start_date',
        'valid_until',
    ];

    public function recordSubscriptionCreated(Subscription $subscription, ?string $source = null): void
    {
        $snapshot = $this->subscriptionSnapshot($subscription);
        $eventType = match (true) {
            $subscription->status === 'trial' => self::TRIAL_STARTED,
            ! empty($subscription->plan_id) => self::PLAN_ASSIGNED,
            $subscription->status === 'active' => self::SUBSCRIPTION_ACTIVATED,
            default => self::SUBSCRIPTION_CREATED,
        };

        $this->record(
            $eventType,
            (int) $subscription->organization_id,
            [
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'source' => $source,
                'new_values' => $snapshot,
                'metadata' => [
                    'included_events' => $this->includedCreatedEvents($subscription),
                    'primary_event' => $eventType,
                ],
                'idempotency_key' => $eventType . ':' . $subscription->id . ':created',
            ]
        );
    }

    public function recordSubscriptionUpdated(Subscription $subscription, ?string $source = null): void
    {
        if (! $this->hasRelevantSubscriptionChanges($subscription)) {
            return;
        }

        $old = $this->subscriptionSnapshotFromValues($subscription->getRawOriginal());
        $new = $this->subscriptionSnapshotFromValues($subscription->getAttributes());
        $organizationId = (int) $subscription->organization_id;
        $eventType = $this->primaryUpdatedEvent($old, $new);

        if (! $eventType) {
            return;
        }

        $this->record($eventType, $organizationId, [
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'source' => $source,
            'old_values' => $old,
            'new_values' => $new,
            'metadata' => [
                'included_events' => $this->includedUpdatedEvents($old, $new),
                'primary_event' => $eventType,
            ],
        ]);
    }

    public function recordResourceAddonCreated(ResourceAddon $addon, ?string $source = null): void
    {
        $this->record(self::ADD_ON_PURCHASED, (int) $addon->organization_id, [
            'resource_addon_id' => $addon->id,
            'source' => $source,
            'new_values' => $this->resourceAddonSnapshot($addon),
            'metadata' => [
                'resource' => $addon->resource,
                'quantity' => $addon->quantity,
            ],
            'idempotency_key' => 'add-on-purchased:' . $addon->id,
        ]);
    }

    public function recordResourceAddonDeleted(ResourceAddon $addon, ?string $source = null): void
    {
        $this->record(self::ADD_ON_DELETED, (int) $addon->organization_id, [
            'resource_addon_id' => $addon->id,
            'source' => $source,
            'old_values' => $this->resourceAddonSnapshot($addon),
            'metadata' => [
                'resource' => $addon->resource,
                'quantity' => $addon->quantity,
            ],
        ]);
    }

    public function recordDueEventsForOrganization(int $organizationId): void
    {
        $subscription = Subscription::where('organization_id', $organizationId)->latest('id')->first();

        if ($subscription && $subscription->valid_until && Carbon::parse($subscription->getRawOriginal('valid_until') ?: $subscription->valid_until)->lt(now())) {
            $validUntil = $subscription->getRawOriginal('valid_until') ?: $subscription->valid_until;

            if ($subscription->status === 'trial') {
                $this->record(self::TRIAL_ENDED, $organizationId, [
                    'subscription_id' => $subscription->id,
                    'plan_id' => $subscription->plan_id,
                    'source' => 'system',
                    'new_values' => $this->subscriptionSnapshot($subscription),
                    'idempotency_key' => self::TRIAL_ENDED . ':' . $subscription->id . ':' . $validUntil,
                    'occurred_at' => Carbon::parse($validUntil),
                ]);

                return;
            }

            $this->record(self::SUBSCRIPTION_EXPIRED, $organizationId, [
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'source' => 'system',
                'new_values' => $this->subscriptionSnapshot($subscription),
                'idempotency_key' => self::SUBSCRIPTION_EXPIRED . ':' . $subscription->id . ':' . $validUntil,
                'occurred_at' => Carbon::parse($validUntil),
            ]);
        }

        ResourceAddon::where('organization_id', $organizationId)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->each(function (ResourceAddon $addon): void {
                $validUntil = $addon->getRawOriginal('valid_until') ?: $addon->valid_until;
                $this->record(self::ADD_ON_EXPIRED, (int) $addon->organization_id, [
                    'resource_addon_id' => $addon->id,
                    'source' => 'system',
                    'old_values' => $this->resourceAddonSnapshot($addon),
                    'metadata' => [
                        'resource' => $addon->resource,
                        'quantity' => $addon->quantity,
                    ],
                    'idempotency_key' => 'add-on-expired:' . $addon->id . ':' . $validUntil,
                    'occurred_at' => Carbon::parse($validUntil),
                ]);
            });
    }

    public function record(string $eventType, int $organizationId, array $attributes = []): ?SubscriptionEvent
    {
        if (! Cache::remember('schema:has_table:subscription_events', now()->addHour(), fn () => Schema::hasTable('subscription_events'))) {
            return null;
        }

        $idempotencyKey = $attributes['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = SubscriptionEvent::where('organization_id', $organizationId)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return SubscriptionEvent::create([
            'organization_id' => $organizationId,
            'subscription_id' => $attributes['subscription_id'] ?? null,
            'plan_id' => $attributes['plan_id'] ?? null,
            'resource_addon_id' => $attributes['resource_addon_id'] ?? null,
            'event_type' => $eventType,
            'source' => $attributes['source'] ?? $this->currentGuard() ?? 'system',
            'actor_id' => $attributes['actor_id'] ?? $this->currentActorId(),
            'actor_guard' => $attributes['actor_guard'] ?? $this->currentGuard(),
            'description' => $attributes['description'] ?? $this->description($eventType),
            'idempotency_key' => $idempotencyKey,
            'old_values' => $attributes['old_values'] ?? null,
            'new_values' => $attributes['new_values'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }

    public function subscriptionSnapshot(Subscription $subscription): array
    {
        return $this->subscriptionSnapshotFromValues([
            'id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'status' => $subscription->status,
            'start_date' => $subscription->getRawOriginal('start_date') ?: $subscription->start_date,
            'valid_until' => $subscription->getRawOriginal('valid_until') ?: $subscription->valid_until,
        ]);
    }

    private function subscriptionSnapshotFromValues(array $values): array
    {
        $planId = $values['plan_id'] ?? null;
        $plan = $planId ? SubscriptionPlan::find($planId) : null;

        return [
            'subscription_id' => $values['id'] ?? null,
            'organization_id' => $values['organization_id'] ?? null,
            'plan_id' => $planId,
            'plan_name' => $plan?->name,
            'plan_price' => $plan ? (float) $plan->price : null,
            'plan_period' => $plan?->period,
            'status' => $values['status'] ?? null,
            'start_date' => $values['start_date'] ?? null,
            'valid_until' => $values['valid_until'] ?? null,
        ];
    }

    private function resourceAddonSnapshot(ResourceAddon $addon): array
    {
        return [
            'resource_addon_id' => $addon->id,
            'organization_id' => $addon->organization_id,
            'resource' => $addon->resource,
            'quantity' => $addon->quantity,
            'valid_until' => $addon->getRawOriginal('valid_until') ?: $addon->valid_until,
            'notes' => $addon->notes,
        ];
    }

    private function hasRelevantSubscriptionChanges(Subscription $subscription): bool
    {
        foreach (self::RELEVANT_SUBSCRIPTION_FIELDS as $field) {
            if ($subscription->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }

    private function planChangeDirection(array $old, array $new): ?string
    {
        if (($old['plan_price'] ?? null) === null || ($new['plan_price'] ?? null) === null) {
            return null;
        }

        if ((float) $new['plan_price'] > (float) $old['plan_price']) {
            return self::PLAN_UPGRADED;
        }

        if ((float) $new['plan_price'] < (float) $old['plan_price']) {
            return self::PLAN_DOWNGRADED;
        }

        return null;
    }

    private function primaryUpdatedEvent(array $old, array $new): ?string
    {
        if (($old['plan_id'] ?? null) !== ($new['plan_id'] ?? null)) {
            if (empty($old['plan_id']) && ! empty($new['plan_id'])) {
                return self::PLAN_ASSIGNED;
            }

            return $this->planChangeDirection($old, $new) ?: self::PLAN_CHANGED;
        }

        if ($this->dateWasChanged($old['valid_until'] ?? null, $new['valid_until'] ?? null)) {
            return $this->dateWasExtended($old['valid_until'] ?? null, $new['valid_until'] ?? null)
                ? self::SUBSCRIPTION_RENEWED
                : self::SUBSCRIPTION_EXPIRATION_CHANGED;
        }

        if (($old['status'] ?? null) === 'trial' && ($new['status'] ?? null) !== 'trial') {
            return self::TRIAL_ENDED;
        }

        if (($old['status'] ?? null) !== 'active' && ($new['status'] ?? null) === 'active') {
            return self::SUBSCRIPTION_ACTIVATED;
        }

        return null;
    }

    private function includedCreatedEvents(Subscription $subscription): array
    {
        $events = [self::SUBSCRIPTION_CREATED];

        if ($subscription->plan_id) {
            $events[] = self::PLAN_ASSIGNED;
        }

        if ($subscription->status === 'trial') {
            $events[] = self::TRIAL_STARTED;
        }

        if ($subscription->status === 'active') {
            $events[] = self::SUBSCRIPTION_ACTIVATED;
        }

        return array_values(array_unique($events));
    }

    private function includedUpdatedEvents(array $old, array $new): array
    {
        $events = [];

        if (($old['status'] ?? null) === 'trial' && ($new['status'] ?? null) !== 'trial') {
            $events[] = self::TRIAL_ENDED;
        }

        if (($old['status'] ?? null) !== 'active' && ($new['status'] ?? null) === 'active') {
            $events[] = self::SUBSCRIPTION_ACTIVATED;
        }

        if (($old['plan_id'] ?? null) !== ($new['plan_id'] ?? null)) {
            $events[] = empty($old['plan_id']) && ! empty($new['plan_id'])
                ? self::PLAN_ASSIGNED
                : self::PLAN_CHANGED;

            $direction = $this->planChangeDirection($old, $new);
            if ($direction) {
                $events[] = $direction;
            }
        }

        if ($this->dateWasChanged($old['valid_until'] ?? null, $new['valid_until'] ?? null)) {
            $events[] = $this->dateWasExtended($old['valid_until'] ?? null, $new['valid_until'] ?? null)
                ? self::SUBSCRIPTION_RENEWED
                : self::SUBSCRIPTION_EXPIRATION_CHANGED;
        }

        return array_values(array_unique($events));
    }

    private function dateWasChanged(?string $oldDate, ?string $newDate): bool
    {
        if (! $oldDate && ! $newDate) {
            return false;
        }

        if (! $oldDate || ! $newDate) {
            return true;
        }

        return ! Carbon::parse($newDate)->equalTo(Carbon::parse($oldDate));
    }

    private function dateWasExtended(?string $oldDate, ?string $newDate): bool
    {
        if (! $oldDate || ! $newDate) {
            return false;
        }

        return Carbon::parse($newDate)->gt(Carbon::parse($oldDate));
    }

    private function currentGuard(): ?string
    {
        foreach (array_keys(config('auth.guards', [])) as $guard) {
            if (auth()->guard($guard)->check()) {
                return $guard;
            }
        }

        return null;
    }

    private function currentActorId(): ?int
    {
        foreach (array_keys(config('auth.guards', [])) as $guard) {
            if (auth()->guard($guard)->check()) {
                return auth()->guard($guard)->id();
            }
        }

        return null;
    }

    private function description(string $eventType): string
    {
        return match ($eventType) {
            self::SUBSCRIPTION_CREATED => 'Subscription Created',
            self::SUBSCRIPTION_ACTIVATED => 'Subscription Activated',
            self::SUBSCRIPTION_RENEWED => 'Subscription Renewed',
            self::SUBSCRIPTION_EXPIRED => 'Subscription Expired',
            self::SUBSCRIPTION_EXPIRATION_CHANGED => 'Subscription Expiration Changed',
            self::PLAN_ASSIGNED => 'Plan Assigned',
            self::PLAN_CHANGED => 'Plan Changed',
            self::PLAN_UPGRADED => 'Plan Upgraded',
            self::PLAN_DOWNGRADED => 'Plan Downgraded',
            self::ADD_ON_PURCHASED => 'Add-on Purchased',
            self::ADD_ON_EXPIRED => 'Add-on Expired',
            self::ADD_ON_DELETED => 'Add-on Deleted',
            self::TRIAL_STARTED => 'Trial Started',
            self::TRIAL_ENDED => 'Trial Ended',
            default => 'Subscription Event',
        };
    }
}
