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

class SubscriptionUsageService
{
    public const TEMPLATE_MESSAGES = 'template_messages';
    public const CONVERSATIONS = 'conversations';
    public const CONTACTS = 'contacts';
    public const TEAM_MEMBERS = 'team_members';
    public const ORGANIZATIONS = 'organizations';

    private const LIMIT_KEYS = [
        self::TEMPLATE_MESSAGES => 'template_messages_limit',
        self::CONVERSATIONS => 'conversations_limit',
        self::CONTACTS => 'contacts_limit',
        self::TEAM_MEMBERS => 'team_limit',
        self::ORGANIZATIONS => 'organizations_limit',
    ];

    private array $resourceAccountIdCache = [];

    private array $organizationNameCache = [];

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
        $used = $this->used($billingOrganizationId, $resource, $subscription, $usageUserId);

        $summary = [
            'resource' => $resource,
            'organization_id' => $organizationId,
            'billing_organization_id' => $billingOrganizationId,
            'billing_organization_name' => $this->organizationName($billingOrganizationId),
            'pool_organization_count' => $poolOrganizationCount,
            'is_shared_pool' => $poolOrganizationCount > 1 || $billingOrganizationId !== $organizationId,
            'limit' => $limit,
            'base_limit' => $baseLimit,
            'addon_quantity' => $addonQuantity,
            'used' => $used,
            'remaining' => $limit === -1 ? -1 : max(0, $limit - $used),
        ];

        if ($resource === self::TEMPLATE_MESSAGES) {
            $summary['source_breakdown'] = $this->templateMessageSourceBreakdown($billingOrganizationId, $subscription);
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
        $subscription = $this->subscription($this->resourceAccountId($organizationId));

        try {
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

    private function conversationWindowsCount(int $billingOrganizationId, ?Subscription $subscription): int
    {
        $query = $this->scopeToResourcePool(ConversationWindow::query(), $billingOrganizationId);
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

        return $this->resourceAccountIdCache[$organizationId] = $billingOrganizationId ?: $organizationId;
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
        return $this->resourcePoolOrganizationIdQuery($billingOrganizationId)->count();
    }

    private function scopeToResourcePool($query, int $billingOrganizationId, string $column = 'organization_id')
    {
        return $query->whereIn($column, $this->resourcePoolOrganizationIdQuery($billingOrganizationId));
    }

    private function resourcePoolOrganizationIdQuery(int $billingOrganizationId)
    {
        return Organization::query()
            ->select('id')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($billingOrganizationId): void {
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

        if ($subscription->status === 'trial' && $subscription->valid_until > now()) {
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
        $limit = optional(\App\Models\Setting::where('key', 'trial_limits')->first())->value;
        $limits = $limit ? json_decode($limit, true) : [];
        $limits = is_array($limits) ? $limits : [];

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
        return Subscription::with('plan')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();
    }

    private function scopeToSubscriptionPeriod($query, ?Subscription $subscription, string $column): void
    {
        if (! $subscription) {
            return;
        }

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
