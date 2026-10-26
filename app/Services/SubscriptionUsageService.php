<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ConversationWindow;
use App\Models\Organization;
use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUsageEvent;
use App\Models\Team;
use App\Models\TeamInvite;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscriptionUsageService
{
    public const TEMPLATE_MESSAGES = 'template_messages';
    public const CONVERSATIONS = 'conversations';
    public const CONTACTS = 'contacts';
    public const TEAM_MEMBERS = 'team_members';
    public const ORGANIZATIONS = 'organizations';

    private const CONTACT_COUNTS_CACHE_PREFIX = 'subscription:usage:contacts-counts:';
    private const CONTACT_COUNTS_CACHE_TTL_SECONDS = 60;

    private const LIMIT_KEYS = [
        self::TEMPLATE_MESSAGES => 'template_messages_limit',
        self::CONVERSATIONS => 'conversations_limit',
        self::CONTACTS => 'contacts_limit',
        self::TEAM_MEMBERS => 'team_limit',
        self::ORGANIZATIONS => 'organizations_limit',
    ];

    private array $resourceAccountIdCache = [];

    private array $organizationNameCache = [];

    private array $subscriptionCache = [];

    private array $resourceSharingEnabledCache = [];

    public function canUse(int $organizationId, string $resource, int $amount = 1, ?int $userId = null): array
    {
        $summary = $this->summary($organizationId, $resource, $userId);

        if ($summary['limit'] === -1) {
            return [
                'allowed' => true,
                'message' => null,
                'summary' => $summary,
            ];
        }

        $allowed = $summary['remaining'] >= $amount;

        return [
            'allowed' => $allowed,
            'message' => $allowed ? null : $this->limitMessage($resource),
            'summary' => $summary,
        ];
    }

    public function summary(int $organizationId, string $resource, ?int $userId = null): array
    {
        $resource = $this->normalizeResource($resource);
        $usageUserId = $userId ?: Auth::id();
        $billingOrganizationId = $this->resourceAccountId($organizationId);
        $poolOrganizationCount = $this->resourcePoolOrganizationCount($billingOrganizationId);
        $subscription = $this->subscription($billingOrganizationId);
        $baseLimit = $this->baseLimit($subscription, $resource);
        $addonQuantity = $this->activeAddonQuantity($billingOrganizationId, $resource);
        $limit = $baseLimit === -1 ? -1 : $baseLimit + $addonQuantity;
        $poolUsed = $this->used($billingOrganizationId, $resource, $subscription, $usageUserId);
        $isResourceAccount = $billingOrganizationId === $organizationId;
        $used = $isResourceAccount
            ? $poolUsed
            : $this->usedForOrganization($organizationId, $resource, $subscription);

        $summary = [
            'resource' => $resource,
            'organization_id' => $organizationId,
            'billing_organization_id' => $billingOrganizationId,
            'billing_organization_name' => $this->organizationName($billingOrganizationId),
            'pool_organization_count' => $poolOrganizationCount,
            'is_shared_pool' => $poolOrganizationCount > 1 || $billingOrganizationId !== $organizationId,
            'is_resource_account' => $isResourceAccount,
            'limit' => $limit,
            'base_limit' => $baseLimit,
            'addon_quantity' => $addonQuantity,
            'used' => $used,
            'pool_used' => $poolUsed,
            'remaining' => $limit === -1 ? -1 : max(0, $limit - $poolUsed),
        ];

        if ($resource === self::TEMPLATE_MESSAGES) {
            $summary['source_breakdown'] = $isResourceAccount
                ? $this->templateMessageSourceBreakdown($billingOrganizationId, $subscription)
                : $this->templateMessageSourceBreakdownForOrganization($organizationId, $subscription);
        }

        if ($resource === self::ORGANIZATIONS) {
            $summary['organization_breakdown'] = $this->resourcePoolOrganizationBreakdown($billingOrganizationId, $subscription);
        }

        return $summary;
    }

    public function summaries(int $organizationId, ?int $userId = null): array
    {
        $resources = [
            self::TEMPLATE_MESSAGES,
            self::CONVERSATIONS,
            self::CONTACTS,
            self::TEAM_MEMBERS,
            self::ORGANIZATIONS,
        ];

        return collect($resources)
            ->mapWithKeys(fn (string $resource) => [$resource => $this->summary($organizationId, $resource, $userId)])
            ->all();
    }

    public function bulkSummaries(array $organizationIds, ?int $userId = null): array
    {
        $organizationIds = collect($organizationIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($organizationIds === []) {
            return [];
        }

        $context = $this->bulkUsageContext($organizationIds);
        $usedByBilling = $this->bulkUsedByBilling($context);
        $addonsByBilling = $this->bulkActiveAddonQuantities($context['billing_organization_ids']);
        $results = [];

        foreach ($organizationIds as $organizationId) {
            $billingOrganizationId = $context['organization_to_billing'][$organizationId] ?? $organizationId;
            $pool = $context['pools'][$billingOrganizationId] ?? [
                'count' => 1,
                'breakdown' => [],
            ];
            $poolOrganizationCount = (int) $pool['count'];
            $isSharedPool = $poolOrganizationCount > 1 || $billingOrganizationId !== $organizationId;
            $subscription = $context['subscriptions']->get($billingOrganizationId);
            $summaries = [];

            foreach ($this->resources() as $resource) {
                $baseLimit = $this->baseLimit($subscription, $resource);
                $addonQuantity = (int) ($addonsByBilling[$billingOrganizationId][$resource] ?? 0);
                $limit = $baseLimit === -1 ? -1 : $baseLimit + $addonQuantity;
                $used = $resource === self::ORGANIZATIONS
                    ? $poolOrganizationCount
                    : (int) ($usedByBilling['totals'][$billingOrganizationId][$resource] ?? 0);

                $summary = [
                    'resource' => $resource,
                    'subscription_id' => $subscription?->id,
                    'period_version' => (int) $subscription?->period_version,
                    'organization_id' => $organizationId,
                    'billing_organization_id' => $billingOrganizationId,
                    'billing_organization_name' => $context['billing_organization_names'][$billingOrganizationId] ?? null,
                    'pool_organization_count' => $poolOrganizationCount,
                    'is_shared_pool' => $isSharedPool,
                    'limit' => $limit,
                    'base_limit' => $baseLimit,
                    'addon_quantity' => $addonQuantity,
                    'used' => $used,
                    'remaining' => $limit === -1 ? -1 : max(0, $limit - $used),
                ];

                if ($resource === self::TEMPLATE_MESSAGES) {
                    $summary['source_breakdown'] = $usedByBilling['template_source_breakdown'][$billingOrganizationId] ?? [];
                }

                if ($resource === self::ORGANIZATIONS) {
                    $summary['organization_breakdown'] = $pool['breakdown'];
                }

                $summaries[$resource] = $summary;
            }

            $results[$organizationId] = $summaries;
        }

        return $results;
    }

    public function recordUsage(
        int $organizationId,
        string $resource,
        int $quantity,
        string $idempotencyKey,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?SubscriptionUsageEvent {
        $resource = $this->normalizeResource($resource);
        try {
            return DB::transaction(function () use ($organizationId, $resource, $quantity, $idempotencyKey,
                $sourceType, $sourceId, $metadata, $occurredAt) {
                $subscription = Subscription::query()->where('organization_id', $this->resourceAccountId($organizationId))
                    ->orderByDesc('id')->lockForUpdate()->first();
                return SubscriptionUsageEvent::create([
                    'organization_id' => $organizationId,
                    'subscription_id' => $subscription?->id,
                    'resource' => $resource,
                    'quantity' => $quantity,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'occurred_at' => $occurredAt ?: now(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return SubscriptionUsageEvent::where('organization_id', $organizationId)
                    ->where('resource', $resource)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
            }

            throw $exception;
        }
    }

    public function ensureConversationWindowForInbound(int $organizationId, int $contactId, ?int $chatId = null, ?Carbon $openedAt = null): ConversationWindow
    {
        $openedAt = $openedAt ?: now();
        return DB::transaction(function () use ($organizationId, $contactId, $chatId, $openedAt) {
            Subscription::query()->where('organization_id', $this->resourceAccountId($organizationId))
                ->orderByDesc('id')->lockForUpdate()->first();

            $activeWindow = ConversationWindow::where('organization_id', $organizationId)
                ->where('contact_id', $contactId)
                ->where('opened_at', '<=', $openedAt)
                ->where('closes_at', '>', $openedAt)
                ->latest('opened_at')
                ->first();

            if ($activeWindow) {
                return $activeWindow;
            }

            return ConversationWindow::create([
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'opened_at' => $openedAt,
                'closes_at' => $openedAt->copy()->addHours(24),
                'source_chat_id' => $chatId,
            ]);
        });
    }

    public function createOrganizationAllowedForUser(int $userId, ?int $anchorOrganizationId = null): array
    {
        $anchorOrganizationId = $anchorOrganizationId
            ?: $this->primaryOwnedOrganizationId($userId)
            ?: $this->firstOwnedOrganizationId($userId);

        if (! $anchorOrganizationId) {
            return [
                'allowed' => true,
                'message' => null,
                'summary' => null,
            ];
        }

        return $this->canUse($anchorOrganizationId, self::ORGANIZATIONS, 1, $userId);
    }

    public function normalizeResource(string $resource): string
    {
        return match ($resource) {
            'template_messages_limit' => self::TEMPLATE_MESSAGES,
            'conversations_limit', 'message_limit' => self::CONVERSATIONS,
            'contacts_limit' => self::CONTACTS,
            'team_limit', 'team_members_limit' => self::TEAM_MEMBERS,
            'organizations_limit', 'whatsapp_numbers_limit' => self::ORGANIZATIONS,
            default => $resource,
        };
    }

    private function resources(): array
    {
        return [
            self::TEMPLATE_MESSAGES,
            self::CONVERSATIONS,
            self::CONTACTS,
            self::TEAM_MEMBERS,
            self::ORGANIZATIONS,
        ];
    }

    public function bulkResourceAccountIds(array $organizationIds): array
    {
        $organizationIds = collect($organizationIds)->map(fn ($id) => (int) $id)
            ->filter()->unique()->values()->all();
        if ($organizationIds === []) {
            return [];
        }

        return $this->bulkResourceAccountContext($organizationIds)['organization_to_billing'];
    }

    private function bulkResourceAccountContext(array $organizationIds): array
    {
        $requestedOrganizations = Organization::query()
            ->whereIn('id', $organizationIds)
            ->get(['id', 'name', 'billing_organization_id'])
            ->keyBy('id');

        $candidateBillingIds = $requestedOrganizations
            ->pluck('billing_organization_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $potentialBillingIds = collect($organizationIds)
            ->merge($candidateBillingIds)
            ->unique()
            ->values()
            ->all();

        $subscriptions = $this->latestSubscriptionsForOrganizations($potentialBillingIds);
        $sharingEnabled = collect($potentialBillingIds)
            ->mapWithKeys(function (int $organizationId) use ($subscriptions): array {
                $enabled = $this->subscriptionEnablesResourceSharing($subscriptions->get($organizationId));
                $this->resourceSharingEnabledCache[$organizationId] = $enabled;

                return [$organizationId => $enabled];
            })
            ->all();

        $organizationToBilling = [];
        foreach ($organizationIds as $organizationId) {
            $organization = $requestedOrganizations->get($organizationId);
            $candidateBillingId = $organization?->billing_organization_id
                ? (int) $organization->billing_organization_id
                : null;

            $organizationToBilling[$organizationId] = $candidateBillingId && ($sharingEnabled[$candidateBillingId] ?? false)
                ? $candidateBillingId
                : $organizationId;

            $this->resourceAccountIdCache[$organizationId] = $organizationToBilling[$organizationId];
        }

        return [
            'organization_to_billing' => $organizationToBilling,
            'subscriptions' => $subscriptions,
            'sharing_enabled' => $sharingEnabled,
        ];
    }

    private function bulkUsageContext(array $organizationIds): array
    {
        $accounts = $this->bulkResourceAccountContext($organizationIds);
        $organizationToBilling = $accounts['organization_to_billing'];
        $subscriptions = $accounts['subscriptions'];
        $sharingEnabled = $accounts['sharing_enabled'];
        $billingOrganizationIds = collect($organizationToBilling)
            ->values()
            ->unique()
            ->values()
            ->all();

        $billingOrganizations = Organization::query()
            ->whereIn('id', $billingOrganizationIds)
            ->get(['id', 'name', 'billing_organization_id'])
            ->keyBy('id');

        foreach ($billingOrganizationIds as $billingOrganizationId) {
            $this->organizationNameCache[$billingOrganizationId] = $billingOrganizations->get($billingOrganizationId)?->name;

            if (! array_key_exists($billingOrganizationId, $sharingEnabled)) {
                $enabled = $this->subscriptionEnablesResourceSharing($subscriptions->get($billingOrganizationId));
                $sharingEnabled[$billingOrganizationId] = $enabled;
                $this->resourceSharingEnabledCache[$billingOrganizationId] = $enabled;
            }
        }

        $poolRows = Organization::query()
            ->where(function ($query) use ($billingOrganizationIds): void {
                $query->whereIn('id', $billingOrganizationIds)
                    ->orWhereIn('billing_organization_id', $billingOrganizationIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'billing_organization_id']);

        $poolRowsById = $poolRows->keyBy('id');
        $childrenByBilling = $poolRows
            ->filter(fn (Organization $organization) => $organization->billing_organization_id !== null)
            ->groupBy('billing_organization_id');
        $pools = [];
        $scopeRows = [];

        foreach ($billingOrganizationIds as $billingOrganizationId) {
            $poolOrganizations = collect();

            if ($poolRowsById->has($billingOrganizationId)) {
                $poolOrganizations->push($poolRowsById->get($billingOrganizationId));
            }

            if ($sharingEnabled[$billingOrganizationId] ?? false) {
                $poolOrganizations = $poolOrganizations
                    ->merge($childrenByBilling->get($billingOrganizationId, collect()))
                    ->unique('id')
                    ->values();
            }

            if ($poolOrganizations->isEmpty()) {
                $poolOrganizations = collect([(object) [
                    'id' => $billingOrganizationId,
                    'name' => $billingOrganizations->get($billingOrganizationId)?->name,
                    'billing_organization_id' => null,
                ]]);
            }

            $period = $this->subscriptionPeriodBounds($subscriptions->get($billingOrganizationId));
            $poolIds = $poolOrganizations
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            foreach ($poolIds as $poolOrganizationId) {
                $scopeRows[] = [
                    'billing_organization_id' => $billingOrganizationId,
                    'organization_id' => $poolOrganizationId,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'usage_event_start_id' => $period['usage_event_start_id'],
                    'conversation_start_id' => $period['conversation_start_id'],
                ];
            }

            $pools[$billingOrganizationId] = [
                'ids' => $poolIds,
                'count' => count($poolIds),
                'breakdown' => $poolOrganizations
                    ->sortBy(fn ($organization) => (int) $organization->id === $billingOrganizationId ? '' : (string) $organization->name)
                    ->values()
                    ->map(fn ($organization) => [
                        'organization_id' => (int) $organization->id,
                        'organization_name' => $organization->name,
                        'role' => (int) $organization->id === $billingOrganizationId
                            ? 'resource_account'
                            : 'shared_account',
                        'counts_as' => 1,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'organization_to_billing' => $organizationToBilling,
            'billing_organization_ids' => $billingOrganizationIds,
            'billing_organization_names' => $billingOrganizations
                ->mapWithKeys(fn (Organization $organization) => [(int) $organization->id => $organization->name])
                ->all(),
            'subscriptions' => $subscriptions,
            'pools' => $pools,
            'scope_rows' => $scopeRows,
        ];
    }

    private function latestSubscriptionsForOrganizations(array $organizationIds)
    {
        $organizationIds = collect($organizationIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($organizationIds === []) {
            return collect();
        }

        $latestSubscriptionIds = Subscription::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('organization_id', $organizationIds)
            ->groupBy('organization_id');

        $subscriptions = Subscription::query()
            ->with('plan')
            ->whereIn('id', $latestSubscriptionIds)
            ->get()
            ->keyBy('organization_id');

        foreach ($subscriptions as $organizationId => $subscription) {
            $this->subscriptionCache[(int) $organizationId] = $subscription;
        }

        return $subscriptions;
    }

    private function subscriptionEnablesResourceSharing(?Subscription $subscription): bool
    {
        $metadata = json_decode((string) $subscription?->plan?->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];

        return (bool) ((int) ($metadata['resource_sharing_enabled'] ?? 0));
    }

    private function subscriptionPeriodBounds(?Subscription $subscription): array
    {
        if (! $subscription) {
            return ['start' => null, 'end' => null, 'usage_event_start_id' => 0, 'conversation_start_id' => 0];
        }

        $startDate = $subscription->getRawOriginal('start_date') ?: $subscription->start_date;
        $validUntil = $subscription->getRawOriginal('valid_until') ?: $subscription->valid_until;

        return [
            'start' => $startDate ? Carbon::parse($startDate)->toDateTimeString() : null,
            'end' => $validUntil ? Carbon::parse($validUntil)->toDateTimeString() : null,
            'usage_event_start_id' => (int) $subscription->usage_event_start_id,
            'conversation_start_id' => (int) $subscription->conversation_start_id,
        ];
    }

    private function bulkActiveAddonQuantities(array $billingOrganizationIds): array
    {
        if ($billingOrganizationIds === []) {
            return [];
        }

        $rows = ResourceAddon::query()
            ->whereIn('organization_id', $billingOrganizationIds)
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            })
            ->selectRaw('organization_id, resource, SUM(quantity) as total')
            ->groupBy('organization_id', 'resource')
            ->get();

        $addons = [];
        foreach ($rows as $row) {
            $addons[(int) $row->organization_id][(string) $row->resource] = (int) $row->total;
        }

        return $addons;
    }

    private function bulkUsedByBilling(array $context): array
    {
        $totals = [];
        foreach ($context['billing_organization_ids'] as $billingOrganizationId) {
            foreach ($this->resources() as $resource) {
                $totals[$billingOrganizationId][$resource] = 0;
            }

            $totals[$billingOrganizationId][self::ORGANIZATIONS] = (int) ($context['pools'][$billingOrganizationId]['count'] ?? 1);
        }

        $poolIdsByBilling = collect($context['pools'])
            ->map(fn (array $pool) => $pool['ids'] ?? [])
            ->all();
        $allPoolOrganizationIds = collect($poolIdsByBilling)
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $contactCounts = $this->contactCountsByOrganization($allPoolOrganizationIds);
        $teamCounts = $this->countsByOrganization(
            Team::query()->whereNull('deleted_at'),
            $allPoolOrganizationIds
        );
        $inviteCounts = $this->countsByOrganization(
            TeamInvite::query()->where('expire_at', '>=', now()),
            $allPoolOrganizationIds
        );

        foreach ($poolIdsByBilling as $billingOrganizationId => $poolOrganizationIds) {
            $totals[$billingOrganizationId][self::CONTACTS] = $this->sumCountsForPool($contactCounts, $poolOrganizationIds);
            $totals[$billingOrganizationId][self::TEAM_MEMBERS] =
                $this->sumCountsForPool($teamCounts, $poolOrganizationIds)
                + $this->sumCountsForPool($inviteCounts, $poolOrganizationIds);
        }

        $templateSourceBreakdown = $this->scopedTemplateSourceBreakdown($context['scope_rows']);
        foreach ($templateSourceBreakdown as $billingOrganizationId => $sources) {
            $totals[$billingOrganizationId][self::TEMPLATE_MESSAGES] = collect($sources)->sum('quantity');
        }

        foreach ($this->scopedConversationTotals($context['scope_rows']) as $billingOrganizationId => $total) {
            $totals[$billingOrganizationId][self::CONVERSATIONS] = $total;
        }

        return [
            'totals' => $totals,
            'template_source_breakdown' => $templateSourceBreakdown,
        ];
    }

    private function contactCountsByOrganization(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        sort($organizationIds);

        return Cache::remember(
            self::CONTACT_COUNTS_CACHE_PREFIX . md5(implode(',', $organizationIds)),
            self::CONTACT_COUNTS_CACHE_TTL_SECONDS,
            function () use ($organizationIds): array {
                $table = DB::getDriverName() === 'mysql'
                    ? DB::raw('`contacts` FORCE INDEX (`idx_contacts_org_deleted`)')
                    : 'contacts';

                return DB::table($table)
                    ->whereNull('deleted_at')
                    ->whereIn('organization_id', $organizationIds)
                    ->selectRaw('organization_id, COUNT(*) as total')
                    ->groupBy('organization_id')
                    ->pluck('total', 'organization_id')
                    ->map(fn ($total) => (int) $total)
                    ->all();
            }
        );
    }

    private function countsByOrganization($query, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return $query
            ->whereIn('organization_id', $organizationIds)
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function sumCountsForPool(array $countsByOrganization, array $poolOrganizationIds): int
    {
        return collect($poolOrganizationIds)
            ->sum(fn ($organizationId) => (int) ($countsByOrganization[(int) $organizationId] ?? 0));
    }

    private function scopedTemplateSourceBreakdown(array $scopeRows): array
    {
        $breakdown = [];

        foreach (array_chunk($scopeRows, 500) as $chunk) {
            [$scopeSql, $bindings] = $this->scopeRowsSql($chunk);
            $rows = DB::select(
                "SELECT scope.billing_organization_id,
                        COALESCE(events.source_type, 'unknown') as source_type,
                        SUM(events.quantity) as total
                 FROM subscription_usage_events events
                 INNER JOIN ({$scopeSql}) scope ON scope.organization_id = events.organization_id
                 WHERE events.resource = ?
                   AND events.id > scope.usage_event_start_id
                   AND (scope.period_start IS NULL OR events.occurred_at >= scope.period_start)
                   AND (scope.period_end IS NULL OR events.occurred_at <= scope.period_end)
                 GROUP BY scope.billing_organization_id, COALESCE(events.source_type, 'unknown')",
                array_merge($bindings, [self::TEMPLATE_MESSAGES])
            );

            foreach ($rows as $row) {
                $billingOrganizationId = (int) $row->billing_organization_id;
                $source = (string) $row->source_type;
                $breakdown[$billingOrganizationId][$source] = ($breakdown[$billingOrganizationId][$source] ?? 0) + (int) $row->total;
            }
        }

        foreach ($breakdown as $billingOrganizationId => $sources) {
            arsort($sources);
            $breakdown[$billingOrganizationId] = collect($sources)
                ->map(fn (int $quantity, string $source) => [
                    'source' => $source,
                    'quantity' => $quantity,
                ])
                ->values()
                ->all();
        }

        return $breakdown;
    }

    private function scopedConversationTotals(array $scopeRows): array
    {
        $totals = [];

        foreach (array_chunk($scopeRows, 500) as $chunk) {
            [$scopeSql, $bindings] = $this->scopeRowsSql($chunk);
            $rows = DB::select(
                "SELECT scope.billing_organization_id, COUNT(*) as total
                 FROM conversation_windows windows
                 INNER JOIN ({$scopeSql}) scope ON scope.organization_id = windows.organization_id
                 WHERE (scope.period_start IS NULL OR windows.opened_at >= scope.period_start)
                   AND windows.id > scope.conversation_start_id
                   AND (scope.period_end IS NULL OR windows.opened_at <= scope.period_end)
                 GROUP BY scope.billing_organization_id",
                $bindings
            );

            foreach ($rows as $row) {
                $billingOrganizationId = (int) $row->billing_organization_id;
                $totals[$billingOrganizationId] = ($totals[$billingOrganizationId] ?? 0) + (int) $row->total;
            }
        }

        return $totals;
    }

    private function scopeRowsSql(array $scopeRows): array
    {
        $selects = [];
        $bindings = [];

        foreach ($scopeRows as $row) {
            $selects[] = 'SELECT ? AS billing_organization_id, ? AS organization_id, ? AS period_start, ? AS period_end, ? AS usage_event_start_id, ? AS conversation_start_id';
            $bindings[] = (int) $row['billing_organization_id'];
            $bindings[] = (int) $row['organization_id'];
            $bindings[] = $row['period_start'];
            $bindings[] = $row['period_end'];
            $bindings[] = $row['usage_event_start_id'];
            $bindings[] = $row['conversation_start_id'];
        }

        return [implode(' UNION ALL ', $selects), $bindings];
    }

    private function used(int $billingOrganizationId, string $resource, ?Subscription $subscription, ?int $userId = null): int
    {
        return match ($resource) {
            self::TEMPLATE_MESSAGES => $this->usageEventsSum($billingOrganizationId, self::TEMPLATE_MESSAGES, $subscription),
            self::CONVERSATIONS => $this->conversationWindowsCount($billingOrganizationId, $subscription),
            self::CONTACTS => $this->scopeToResourcePool(Contact::query(), $billingOrganizationId)->whereNull('deleted_at')->count(),
            self::TEAM_MEMBERS => $this->teamMembersAndPendingInvitesCount($billingOrganizationId),
            self::ORGANIZATIONS => $this->resourcePoolOrganizationCount($billingOrganizationId),
            default => 0,
        };
    }

    private function usedForOrganization(int $organizationId, string $resource, ?Subscription $subscription): int
    {
        return match ($resource) {
            self::TEMPLATE_MESSAGES => $this->usageEventsSumForOrganization($organizationId, self::TEMPLATE_MESSAGES, $subscription),
            self::CONVERSATIONS => $this->conversationWindowsCountForOrganization($organizationId, $subscription),
            self::CONTACTS => Contact::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->count(),
            self::TEAM_MEMBERS => $this->teamMembersAndPendingInvitesCountForOrganization($organizationId),
            self::ORGANIZATIONS => 1,
            default => 0,
        };
    }

    private function usageEventsSum(int $billingOrganizationId, string $resource, ?Subscription $subscription): int
    {
        $query = $this->scopeToResourcePool(SubscriptionUsageEvent::query(), $billingOrganizationId)
            ->where('resource', $resource);

        $this->scopeToSubscriptionPeriod($query, $subscription, 'occurred_at');

        return (int) $query->sum('quantity');
    }

    private function templateMessageSourceBreakdown(int $billingOrganizationId, ?Subscription $subscription): array
    {
        $query = $this->scopeToResourcePool(SubscriptionUsageEvent::query(), $billingOrganizationId)
            ->where('resource', self::TEMPLATE_MESSAGES)
            ->selectRaw("COALESCE(source_type, 'unknown') as source_type, SUM(quantity) as total")
            ->groupByRaw("COALESCE(source_type, 'unknown')")
            ->orderByDesc('total');

        $this->scopeToSubscriptionPeriod($query, $subscription, 'occurred_at');

        return $query->get()
            ->map(fn ($row) => [
                'source' => (string) $row->source_type,
                'quantity' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    private function templateMessageSourceBreakdownForOrganization(int $organizationId, ?Subscription $subscription): array
    {
        $query = SubscriptionUsageEvent::query()
            ->where('organization_id', $organizationId)
            ->where('resource', self::TEMPLATE_MESSAGES)
            ->selectRaw("COALESCE(source_type, 'unknown') as source_type, SUM(quantity) as total")
            ->groupByRaw("COALESCE(source_type, 'unknown')")
            ->orderByDesc('total');

        $this->scopeToSubscriptionPeriod($query, $subscription, 'occurred_at');

        return $query->get()
            ->map(fn ($row) => [
                'source' => (string) $row->source_type,
                'quantity' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    private function conversationWindowsCount(int $billingOrganizationId, ?Subscription $subscription): int
    {
        $query = $this->scopeToResourcePool(ConversationWindow::query(), $billingOrganizationId);
        $this->scopeToSubscriptionPeriod($query, $subscription, 'opened_at');

        return $query->count();
    }

    private function usageEventsSumForOrganization(int $organizationId, string $resource, ?Subscription $subscription): int
    {
        $query = SubscriptionUsageEvent::query()
            ->where('organization_id', $organizationId)
            ->where('resource', $resource);

        $this->scopeToSubscriptionPeriod($query, $subscription, 'occurred_at');

        return (int) $query->sum('quantity');
    }

    private function conversationWindowsCountForOrganization(int $organizationId, ?Subscription $subscription): int
    {
        $query = ConversationWindow::query()->where('organization_id', $organizationId);
        $this->scopeToSubscriptionPeriod($query, $subscription, 'opened_at');

        return $query->count();
    }

    private function teamMembersAndPendingInvitesCount(int $billingOrganizationId): int
    {
        $teamCount = $this->scopeToResourcePool(Team::query(), $billingOrganizationId)
            ->whereNull('deleted_at')
            ->count();

        $inviteCount = $this->scopeToResourcePool(TeamInvite::query(), $billingOrganizationId)
            ->where('expire_at', '>=', now())
            ->count();

        return $teamCount + $inviteCount;
    }

    private function teamMembersAndPendingInvitesCountForOrganization(int $organizationId): int
    {
        $teamCount = Team::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();

        $inviteCount = TeamInvite::query()
            ->where('organization_id', $organizationId)
            ->where('expire_at', '>=', now())
            ->count();

        return $teamCount + $inviteCount;
    }

    private function ownedOrganizationsCount(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return Team::where('user_id', $userId)
            ->where('role', 'owner')
            ->whereNull('teams.deleted_at')
            ->whereHas('organization')
            ->count();
    }

    public function resourceAccountId(int $organizationId): int
    {
        if (array_key_exists($organizationId, $this->resourceAccountIdCache)) {
            return $this->resourceAccountIdCache[$organizationId];
        }

        $billingOrganizationId = Organization::whereKey($organizationId)->value('billing_organization_id');
        if ($billingOrganizationId && ! $this->resourceSharingEnabledForOrganization((int) $billingOrganizationId)) {
            $billingOrganizationId = null;
        }

        return $this->resourceAccountIdCache[$organizationId] = $billingOrganizationId ?: $organizationId;
    }

    public function resourceSharingEnabledForOrganization(int $organizationId): bool
    {
        if (array_key_exists($organizationId, $this->resourceSharingEnabledCache)) {
            return $this->resourceSharingEnabledCache[$organizationId];
        }

        return $this->resourceSharingEnabledCache[$organizationId] = Cache::remember(
            "subscription:resource-sharing-enabled:{$organizationId}",
            now()->addMinutes(10),
            function () use ($organizationId): bool {
                $planId = Subscription::query()
                    ->where('subscriptions.organization_id', $organizationId)
                    ->orderByDesc('subscriptions.id')
                    ->value('subscriptions.plan_id');

                if (! $planId) {
                    return false;
                }

                $planMetadata = SubscriptionPlan::query()
                    ->whereKey($planId)
                    ->whereNull('deleted_at')
                    ->value('metadata');

                if (! $planMetadata) {
                    return false;
                }

                $metadata = json_decode((string) $planMetadata, true);
                $metadata = is_array($metadata) ? $metadata : [];

                return (bool) ((int) ($metadata['resource_sharing_enabled'] ?? 0));
            }
        );
    }

    public function forgetResourceSharingCache(int $organizationId): void
    {
        unset($this->resourceSharingEnabledCache[$organizationId]);
        Cache::forget("subscription:resource-sharing-enabled:{$organizationId}");
    }

    public function resourcePoolOrganizationIds(int $billingOrganizationId): array
    {
        return $this->resourcePoolOrganizationIdQuery($billingOrganizationId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resourcePoolOrganizationCount(int $billingOrganizationId): int
    {
        if (! $this->resourceSharingEnabledForOrganization($billingOrganizationId)) {
            return 1;
        }

        return $this->resourcePoolOrganizationIdQuery($billingOrganizationId)->count();
    }

    private function resourcePoolOrganizationBreakdown(int $billingOrganizationId, ?Subscription $subscription = null): array
    {
        $organizations = $this->resourcePoolOrganizationIdQuery($billingOrganizationId)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$billingOrganizationId])
            ->orderBy('name')
            ->get(['id', 'name', 'billing_organization_id'])
            ->values();

        $usageByOrganization = $this->usageByOrganization(
            $organizations->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $subscription
        );

        return $organizations->map(function (Organization $organization) use ($billingOrganizationId, $usageByOrganization): array {
            $usage = $usageByOrganization[(int) $organization->id] ?? [];

            return [
                'organization_id' => (int) $organization->id,
                'organization_name' => $organization->name,
                'role' => (int) $organization->id === $billingOrganizationId
                    ? 'resource_account'
                    : 'shared_account',
                'counts_as' => 1,
                'template_messages_used' => (int) ($usage[self::TEMPLATE_MESSAGES] ?? 0),
                'conversations_used' => (int) ($usage[self::CONVERSATIONS] ?? 0),
                'contacts_used' => (int) ($usage[self::CONTACTS] ?? 0),
                'team_members_used' => (int) ($usage[self::TEAM_MEMBERS] ?? 0),
            ];
        })
            ->values()
            ->all();
    }

    private function usageByOrganization(array $organizationIds, ?Subscription $subscription): array
    {
        $organizationIds = collect($organizationIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($organizationIds === []) {
            return [];
        }

        $usage = collect($organizationIds)->mapWithKeys(fn (int $organizationId) => [
            $organizationId => [
                self::TEMPLATE_MESSAGES => 0,
                self::CONVERSATIONS => 0,
                self::CONTACTS => 0,
                self::TEAM_MEMBERS => 0,
            ],
        ])->all();

        $templateQuery = SubscriptionUsageEvent::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('resource', self::TEMPLATE_MESSAGES);
        $this->scopeToSubscriptionPeriod($templateQuery, $subscription, 'occurred_at');

        foreach ($templateQuery
            ->selectRaw('organization_id, SUM(quantity) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id') as $organizationId => $total) {
            $usage[(int) $organizationId][self::TEMPLATE_MESSAGES] = (int) $total;
        }

        $conversationQuery = ConversationWindow::query()
            ->whereIn('organization_id', $organizationIds);
        $this->scopeToSubscriptionPeriod($conversationQuery, $subscription, 'opened_at');

        foreach ($conversationQuery
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id') as $organizationId => $total) {
            $usage[(int) $organizationId][self::CONVERSATIONS] = (int) $total;
        }

        foreach (Contact::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id') as $organizationId => $total) {
            $usage[(int) $organizationId][self::CONTACTS] = (int) $total;
        }

        foreach (Team::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id') as $organizationId => $total) {
            $usage[(int) $organizationId][self::TEAM_MEMBERS] += (int) $total;
        }

        foreach (TeamInvite::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('expire_at', '>=', now())
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id') as $organizationId => $total) {
            $usage[(int) $organizationId][self::TEAM_MEMBERS] += (int) $total;
        }

        return $usage;
    }

    private function scopeToResourcePool($query, int $billingOrganizationId, string $column = 'organization_id')
    {
        if (! $this->resourceSharingEnabledForOrganization($billingOrganizationId)) {
            return $query->where($column, $billingOrganizationId);
        }

        return $query->whereIn($column, $this->resourcePoolOrganizationIdQuery($billingOrganizationId));
    }

    private function resourcePoolOrganizationIdQuery(int $billingOrganizationId)
    {
        $query = Organization::query()
            ->select('id')
            ->whereNull('deleted_at');

        if (! $this->resourceSharingEnabledForOrganization($billingOrganizationId)) {
            return $query->whereKey($billingOrganizationId);
        }

        return $query->where(function ($query) use ($billingOrganizationId): void {
            $query->whereKey($billingOrganizationId)
                ->orWhere('billing_organization_id', $billingOrganizationId);
        });
    }

    private function organizationName(int $organizationId): ?string
    {
        if (array_key_exists($organizationId, $this->organizationNameCache)) {
            return $this->organizationNameCache[$organizationId];
        }

        return $this->organizationNameCache[$organizationId] = Organization::whereKey($organizationId)->value('name');
    }

    private function primaryOwnedOrganizationId(int $userId): ?int
    {
        return Team::query()
            ->join('organizations', 'teams.organization_id', '=', 'organizations.id')
            ->where('teams.user_id', $userId)
            ->where('teams.role', 'owner')
            ->whereNull('teams.deleted_at')
            ->whereNull('organizations.deleted_at')
            ->orderBy('organizations.created_at')
            ->orderBy('organizations.id')
            ->value('teams.organization_id');
    }

    private function firstOwnedOrganizationId(int $userId): ?int
    {
        return Team::where('user_id', $userId)
            ->where('role', 'owner')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('organization_id');
    }

    public function effectiveLimit(Subscription $subscription, string $resource): int
    {
        $resource = $this->normalizeResource($resource);
        $base = $this->baseLimit($subscription, $resource);
        return $base === -1 ? -1 : $base + $this->activeAddonQuantity((int) $subscription->organization_id, $resource);
    }

    private function activeAddonQuantity(int $organizationId, string $resource): int
    {
        return (int) ResourceAddon::where('organization_id', $organizationId)
            ->where('resource', $resource)
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            })
            ->sum('quantity');
    }

    private function baseLimit(?Subscription $subscription, string $resource): int
    {
        if (! $subscription) {
            return 0;
        }

        $end = $subscription->getRawOriginal('valid_until');
        if ($subscription->status === 'trial' && $end && Carbon::parse($end, 'UTC')->isFuture()) {
            return $this->trialLimit($resource);
        }

        $plan = $subscription->plan ?: ($subscription->plan_id ? SubscriptionPlan::find($subscription->plan_id) : null);
        if (! $plan) {
            return 0;
        }

        $metadata = json_decode((string) $plan->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $key = self::LIMIT_KEYS[$resource] ?? null;

        if ($key && array_key_exists($key, $metadata)) {
            return (int) $metadata[$key];
        }

        if (in_array($resource, [self::TEMPLATE_MESSAGES, self::CONVERSATIONS], true) && array_key_exists('message_limit', $metadata)) {
            return (int) $metadata['message_limit'];
        }

        return -1;
    }

    private function trialLimit(string $resource): int
    {
        $limits = Cache::remember('settings:trial_limits', now()->addMinutes(10), function (): array {
            $limit = optional(\App\Models\Setting::where('key', 'trial_limits')->first())->value;
            $limits = $limit ? json_decode($limit, true) : [];

            return is_array($limits) ? $limits : [];
        });

        return match ($resource) {
            self::TEMPLATE_MESSAGES => (int) ($limits['template_messages'] ?? $limits['campaign_messages'] ?? $limits['messages'] ?? -1),
            self::CONVERSATIONS => (int) ($limits['conversations'] ?? $limits['messages'] ?? -1),
            self::CONTACTS => (int) ($limits['contacts'] ?? -1),
            self::TEAM_MEMBERS => (int) ($limits['users'] ?? -1),
            self::ORGANIZATIONS => (int) ($limits['organizations'] ?? -1),
            default => -1,
        };
    }

    private function subscription(int $organizationId): ?Subscription
    {
        if (array_key_exists($organizationId, $this->subscriptionCache)) {
            return $this->subscriptionCache[$organizationId];
        }

        return $this->subscriptionCache[$organizationId] = Subscription::with('plan')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();
    }

    private function scopeToSubscriptionPeriod($query, ?Subscription $subscription, string $column): void
    {
        if (! $subscription) {
            return;
        }

        $query->where('id', '>', (int) ($column === 'occurred_at'
            ? $subscription->usage_event_start_id : $subscription->conversation_start_id));

        $startDate = $subscription->getRawOriginal('start_date') ?: $subscription->start_date;
        $validUntil = $subscription->getRawOriginal('valid_until') ?: $subscription->valid_until;

        if ($startDate) {
            $query->where($column, '>=', Carbon::parse($startDate));
        }

        if ($validUntil) {
            $query->where($column, '<=', Carbon::parse($validUntil));
        }
    }

    private function limitMessage(string $resource): string
    {
        return match ($resource) {
            self::TEMPLATE_MESSAGES => __('You have reached your template messages limit. Please renew, upgrade, or purchase additional template messages.'),
            self::CONVERSATIONS => __('You have exceeded the allowed conversations limit. Please renew, upgrade, or purchase additional conversations.'),
            self::CONTACTS => __('You have reached your contacts limit. Please upgrade your account or purchase additional contacts.'),
            self::TEAM_MEMBERS => __('You have reached your team members limit. Please upgrade your account or purchase additional team members.'),
            self::ORGANIZATIONS => __('You have reached your organizations limit. Please upgrade your account before creating another organization.'),
            default => __('You have reached your subscription limit.'),
        };
    }
}
