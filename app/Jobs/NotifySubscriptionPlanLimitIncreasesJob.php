<?php

namespace App\Jobs;

use App\Models\NotificationDefinition;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\ManagedNotifications\NotificationEventRecorder;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class NotifySubscriptionPlanLimitIncreasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LIMIT_KEYS = [
        'template_messages' => 'template_messages_limit',
        'conversations' => 'conversations_limit',
        'contacts' => 'contacts_limit',
        'team_members' => 'team_limit',
        'organizations' => 'organizations_limit',
    ];

    private const RESOURCE_NAMES = [
        'template_messages' => 'Template messages',
        'conversations' => 'Conversations',
        'contacts' => 'Contacts',
        'team_members' => 'Team members',
        'organizations' => 'Organizations',
    ];

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $planId,
        public readonly array $oldMetadata,
        public readonly array $newMetadata,
        public readonly string $changeId,
        public readonly ?int $changeRowId = null,
    ) {
    }

    public function handle(NotificationEventRecorder $recorder, SubscriptionUsageService $usage): void
    {
        $this->process($recorder, $usage);

        if ($this->changeRowId) {
            DB::table('subscription_plan_notification_changes')->where('id', $this->changeRowId)
                ->update(['processed_at' => now(), 'updated_at' => now()]);
        }
    }

    private function process(NotificationEventRecorder $recorder, SubscriptionUsageService $usage): void
    {
        $plan = SubscriptionPlan::query()->find($this->planId);
        if (! $plan) {
            return;
        }

        if (! NotificationDefinition::query()->where('type_key', 'subscription.limit_increased')
            ->where('enabled', true)->exists()) {
            return;
        }

        $increases = [];
        foreach (self::LIMIT_KEYS as $resource => $key) {
            $old = $this->baseLimit($this->oldMetadata, $resource, $key);
            $new = $this->baseLimit($this->newMetadata, $resource, $key);
            if ($this->baseLimit(json_decode((string) $plan->metadata, true) ?: [], $resource, $key) !== $new) {
                continue;
            }
            if ($new !== -1 && ($old === -1 || $new <= $old)) {
                continue;
            }
            if ($old !== -1 && ($new === -1 || $new > $old)) {
                $increases[$resource] = [$old, $new];
            }
        }
        if (! $increases) {
            return;
        }

        Subscription::query()->where('plan_id', $this->planId)->where('status', 'active')
            ->where('valid_until', '>', now())
            ->latestPerOrganization()
            ->orderBy('id')->chunkById(100, function ($subscriptions) use ($recorder, $usage, $increases): void {
                $organizations = Organization::query()->whereIn('id', $subscriptions->pluck('organization_id'))
                    ->whereNull('deleted_at')->get(['id', 'name'])->keyBy('id');
                $summaries = $usage->bulkSummaries($subscriptions->pluck('organization_id')->all());
                $currentPlan = SubscriptionPlan::query()->find($this->planId);
                if (! $currentPlan) {
                    return;
                }
                $currentMetadata = json_decode((string) $currentPlan->metadata, true) ?: [];

                foreach ($subscriptions as $subscription) {
                    $organization = $organizations->get($subscription->organization_id);
                    if (! $organization) {
                        continue;
                    }
                    DB::transaction(function () use ($subscription, $organization, $summaries, $increases, $currentMetadata, $recorder): void {
                        $current = Subscription::query()->lockForUpdate()->find($subscription->id);
                        if (! $current || $current->status !== 'active' || (int) $current->plan_id !== $this->planId
                            || ! $current->getRawOriginal('valid_until')
                            || Carbon::parse($current->getRawOriginal('valid_until'), 'UTC')->lte(now())
                            || (int) Subscription::query()->where('organization_id', $organization->id)->max('id') !== (int) $current->id) {
                            return;
                        }
                        foreach ($increases as $resource => [$oldBase, $newBase]) {
                            if ($this->baseLimit($currentMetadata, $resource, self::LIMIT_KEYS[$resource]) !== $newBase) {
                                continue;
                            }
                            $summary = $summaries[$organization->id][$resource] ?? null;
                            if (! $summary || (int) $summary['billing_organization_id'] !== (int) $organization->id) {
                                continue;
                            }
                            $addons = (int) $summary['addon_quantity'];
                            $oldLimit = $oldBase === -1 ? -1 : $oldBase + $addons;
                            $newLimit = $newBase === -1 ? -1 : $newBase + $addons;
                            $sourceKey = 'subscription-plan-limit:'.$this->changeId.':'.$organization->id.':'.$resource;
                            if ($recorder->hasRecorded((int) $organization->id, 'subscription.limit_increased', $sourceKey)) {
                                continue;
                            }
                            $recorder->record((int) $organization->id, 'subscription.limit_increased', $sourceKey, [
                                'organization_name' => $organization->name,
                                'resource_name' => self::RESOURCE_NAMES[$resource],
                                'old_limit' => $oldLimit === -1 ? 'Unlimited' : (string) $oldLimit,
                                'new_limit' => $newLimit === -1 ? 'Unlimited' : (string) $newLimit,
                            ]);
                        }
                    });
                }
            });
    }

    private function baseLimit(array $metadata, string $resource, string $key): int
    {
        if (array_key_exists($key, $metadata)) {
            return (int) $metadata[$key];
        }

        if (in_array($resource, ['template_messages', 'conversations'], true)
            && array_key_exists('message_limit', $metadata)) {
            return (int) $metadata['message_limit'];
        }

        return -1;
    }
}
