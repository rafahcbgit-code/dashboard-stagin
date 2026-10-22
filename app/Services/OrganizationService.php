<?php

namespace App\Services;

use App\Http\Resources\OrganizationsResource;
use App\Http\Resources\BillingResource;
use App\Http\Resources\UserResource;
use App\Models\BillingCredit;
use App\Models\BillingDebit;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\SubscriptionAuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Str;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    private const CRM_DEFAULT_PAGE_SIZE = 500;
    private const CRM_MAX_PAGE_SIZE = 1000;

    /**
     * Cache key prefix for organization metadata.
     * Story 2.2: AC-2.2.2
     */
    private const CACHE_KEY_PREFIX = 'org-metadata-';

    /**
     * Cache TTL for organization metadata (1 hour).
     * Story 2.2: AC-2.2.2
     */
    private const CACHE_TTL = 3600;

    /**
     * Get cached organization metadata.
     * Story 2.2: AC-2.2.2 - Organization metadata cached for 1 hour with proper invalidation.
     *
     * @param  int  $organizationId
     * @return array Decoded metadata array
     */
    public static function getCachedMetadata(int $organizationId): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX.$organizationId,
            self::CACHE_TTL,
            fn () => json_decode(
                Organization::query()->whereKey($organizationId)->value('metadata') ?? '{}',
                true
            ) ?? []
        );
    }

    /**
     * Invalidate cached organization metadata.
     * Story 2.2: AC-2.2.2 - Called when organization metadata is updated.
     *
     * @param  int  $organizationId
     */
    public static function invalidateMetadataCache(int $organizationId): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX.$organizationId);
    }

    /**
     * Get all organizations based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request, $userId = null)
    {
        $statusFilter = $request->query('subscription_status');
        $organizations = (new Organization)->listAll($request->query('search'), $userId, $statusFilter);

        return OrganizationsResource::collection($organizations);
    }

    public function CRMOrganizations(object $request, $userId = null)
    {
        $statusFilter = $request->query('subscription_status');
        $createdAtRange = $this->resolveCrmCreatedAtRange($request);
        $organizations = (new Organization)
            ->crmListQuery(
                $request->query('search'),
                $userId,
                $statusFilter,
                $createdAtRange['from'],
                $createdAtRange['to']
            )
            ->orderByDesc('id')
            ->cursorPaginate($this->resolveCrmPageSize($request))
            ->withQueryString();
        $dateFormats = $this->crmDateFormats();
        $usageService = new SubscriptionUsageService();
        $usageByOrganization = $usageService->bulkSummaries(
            collect($organizations->items())->pluck('id')->all(),
            $userId
        );

        return $organizations->through(function (Organization $organization) use ($dateFormats, $usageByOrganization) {
            $data = $organization->toArray();
            $teams = $organization->teams;
            $subscription = $organization->subscription;
            $owner = $organization->owner;

            $teamData = $teams->map(function (Team $team) {
                $data = $team->toArray();
                $data['user'] = $team->user?->toArray();

                return $data;
            })->values();

            $data['user'] = $owner?->user?->toArray();
            $data['users'] = $teams
                ->pluck('user')
                ->filter()
                ->values()
                ->map(fn (User $user) => $user->toArray());
            $data['updated_at'] = $this->formatCrmDate($organization->updated_at, $dateFormats);
            $data['created_at'] = $this->formatCrmDate($organization->created_at, $dateFormats);
            $data['plan'] = $subscription?->plan?->toArray();
            $data['team'] = $teamData;
            $data['teams'] = $teamData;
            $data['resource_usage'] = $usageByOrganization[(int) $organization->id] ?? [];

            if ($subscription) {
                $data['subscription']['next_billing_date'] = $subscription->next_billing_date;
            }

            return $data;
        });
    }

    private function resolveCrmPageSize(object $request): int
    {
        $perPage = (int) $request->query('per_page', self::CRM_DEFAULT_PAGE_SIZE);

        if ($perPage < 1) {
            return self::CRM_DEFAULT_PAGE_SIZE;
        }

        return min($perPage, self::CRM_MAX_PAGE_SIZE);
    }

    private function resolveCrmCreatedAtRange(object $request): array
    {
        return [
            'from' => $request->filled('created_from')
                ? Carbon::parse($request->query('created_from'))->startOfDay()
                : null,
            'to' => $request->filled('created_to')
                ? Carbon::parse($request->query('created_to'))->endOfDay()
                : null,
        ];
    }

    private function crmDateFormats(): array
    {
        $formats = Setting::whereIn('key', ['date_format', 'time_format'])
            ->pluck('value', 'key');

        return [
            'date' => $formats['date_format'] ?? 'd-M-y',
            'time' => $formats['time_format'] ?? 'H:i',
        ];
    }

    private function formatCrmDate($date, array $formats): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::create($date)->format($formats['date'] . ' ' . $formats['time']);
    }

    /**
     * Retrieve an organization by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\Organization
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($request, $uuid = null)
    {
        $result['plans'] = SubscriptionPlan::whereNull('deleted_at')->get();
        $plansById = $result['plans']->keyBy('id');
        $sharingPlanIds = $this->resourceSharingPlanIds($result['plans']);
        $result['resource_accounts'] = $this->resourceAccountOptions(null, $sharingPlanIds);

        if ($uuid === null) {
            $result['organization'] = null;
            $result['billing'] = null;
            $result['users'] = null;
            $result['subscription_events'] = [];

            return $result;
        }

        $organization = Organization::with([
            'subscription',
            'billingOrganization:id,name,uuid',
            'resourceOrganizations:id,name,uuid,billing_organization_id',
        ])->where('uuid', $uuid)->first();
        if ($organization?->subscription?->plan_id) {
            $plan = $plansById->get($organization->subscription->plan_id);

            if ($plan) {
                $organization->subscription->setRelation('plan', $plan);
            }
        }
        $users = (new User)->listAll('user', $request->query('search'), $organization->id);
        $billing = (new BillingTransaction)->listAll($request->query('search'), $organization->id);
        app(SubscriptionAuditService::class)->recordDueEventsForOrganization((int) $organization->id);
        $usageService = new SubscriptionUsageService();
        $organization->resource_sharing_enabled = $usageService->resourceSharingEnabledForOrganization((int) $organization->id);
        $organization->resource_account_sharing_enabled = $organization->billing_organization_id
            ? $usageService->resourceSharingEnabledForOrganization((int) $organization->billing_organization_id)
            : false;

        $result['resource_accounts'] = $this->resourceAccountOptions((int) $organization->id, $sharingPlanIds);
        $result['organization'] = $organization;
        $result['billing'] = BillingResource::collection($billing);
        $result['users'] = UserResource::collection($users);
        $result['subscription_events'] = $this->subscriptionEvents((int) $organization->id);

        return $result;
    }

    private function subscriptionEvents(int $organizationId)
    {
        if (! Cache::remember('schema:has_table:subscription_events', now()->addHour(), fn () => Schema::hasTable('subscription_events'))) {
            return collect();
        }

        $events = SubscriptionEvent::query()
            ->where('organization_id', $organizationId)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->visibleSubscriptionEvents($events)
            ->map(fn (SubscriptionEvent $event) => [
                'id' => $event->id,
                'uuid' => $event->uuid,
                'event_type' => $event->event_type,
                'description' => $event->description,
                'source' => $event->source,
                'actor_guard' => $event->actor_guard,
                'actor_id' => $event->actor_id,
                'old_values' => $event->old_values,
                'new_values' => $event->new_values,
                'metadata' => $event->metadata,
                'occurred_at' => optional($event->occurred_at)->toDateTimeString(),
            ]);
    }

    private function visibleSubscriptionEvents($events)
    {
        return $events
            ->groupBy(fn (SubscriptionEvent $event) => implode('|', [
                $event->subscription_id,
                $event->resource_addon_id,
                optional($event->occurred_at)->toDateTimeString(),
                $event->source,
                $event->actor_guard,
                $event->actor_id,
                md5(json_encode($event->old_values ?? [])),
                md5(json_encode($event->new_values ?? [])),
            ]))
            ->map(fn ($group) => $group
                ->sortByDesc(fn (SubscriptionEvent $event) => $this->subscriptionEventPriority($event->event_type))
                ->first()
            )
            ->sortByDesc(fn (SubscriptionEvent $event) => optional($event->occurred_at)->timestamp ?? 0)
            ->values();
    }

    private function subscriptionEventPriority(string $eventType): int
    {
        return match ($eventType) {
            SubscriptionAuditService::PLAN_UPGRADED,
            SubscriptionAuditService::PLAN_DOWNGRADED => 100,
            SubscriptionAuditService::PLAN_ASSIGNED => 95,
            SubscriptionAuditService::PLAN_CHANGED => 90,
            SubscriptionAuditService::SUBSCRIPTION_RENEWED,
            SubscriptionAuditService::SUBSCRIPTION_EXPIRATION_CHANGED => 85,
            SubscriptionAuditService::ADD_ON_PURCHASED,
            SubscriptionAuditService::ADD_ON_EXPIRED,
            SubscriptionAuditService::ADD_ON_DELETED => 80,
            SubscriptionAuditService::TRIAL_STARTED,
            SubscriptionAuditService::TRIAL_ENDED => 70,
            SubscriptionAuditService::SUBSCRIPTION_ACTIVATED,
            SubscriptionAuditService::SUBSCRIPTION_EXPIRED => 60,
            SubscriptionAuditService::SUBSCRIPTION_CREATED => 50,
            default => 0,
        };
    }

    /**
     * Store a new organization based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        return DB::transaction(function () use ($request) {
            if ($request->input('create_user') == 1) {
                //Create and attach user to organization
                $user = User::create([
                    'first_name' => $request->input('first_name'),
                    'last_name' => $request->input('last_name'),
                    'email' => $request->input('email'),
                    'role' => 'user',
                    'code_verified' => 1,
                    'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
                    'address' => json_encode([
                        'street' => $request->input('street'),
                        'city' => $request->input('city'),
                        'state' => $request->input('state'),
                        'zip' => $request->input('zip'),
                        'country' => $request->input('country'),
                    ]),
                    'password' => $request->input('password'),
                ]);
            } else {
                //Attach existng user to organization
                $user = User::where('email', $request->input('email'))->first();
            }

            $timestamp = now()->format('YmdHis');
            $randomString = Str::random(4);
            $userId = $user->id;

            $organization = Organization::create([
                'name' => $request->input('name'),
                'identifier' => $timestamp . $userId . $randomString,
                'address' => json_encode([
                    'street' => $request->street,
                    'city' => $request->city,
                    'state' => $request->state,
                    'zip' => $request->zip,
                    'country' => $request->country,
                ]),
                'billing_organization_id' => $this->resolveBillingOrganizationId($request),
                'metadata' => $this->metadataWithCampaignPhoneMps(null, $this->campaignPhoneMpsFromRequest($request, true)),
                'created_by' => auth()->user()->id,
            ]);

            Team::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
                'created_by' => auth()->user()->id,
            ]);

            $plan = SubscriptionPlan::where('uuid', $request->plan)->first();
            $subscriptionMeta = $this->resolveSubscriptionExpiration($request, $plan);

            Subscription::create([
                'organization_id' => $organization->id,
                'status' => $subscriptionMeta['status'],
                'plan_id' => $plan ? $plan->id : null,
                'start_date' => now(),
                'valid_until' => $subscriptionMeta['valid_until'],
            ]);

            return $organization;
        });
    }

    /**
     * Update organization.
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Organization
     */
    public function update($request, $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $organization = Organization::where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            $updateData = [
                'name' => $request->input('name'),
                'address' => json_encode([
                    'street' => $request->street,
                    'city' => $request->city,
                    'state' => $request->state,
                    'zip' => $request->zip,
                    'country' => $request->country,
                ]),
                'billing_organization_id' => $this->resolveBillingOrganizationId($request, (int) $organization->id),
            ];

            $campaignPhoneMps = $this->campaignPhoneMpsFromRequest($request);
            if ($campaignPhoneMps !== null) {
                $updateData['metadata'] = $this->metadataWithCampaignPhoneMps($organization->metadata, $campaignPhoneMps);
            }

            $organization->update($updateData);

            $subscription = Subscription::where('organization_id', $organization->id)->orderByDesc('id')->lockForUpdate()->first();
            $plan = SubscriptionPlan::where('uuid', $request->plan)->first();
            $subscriptionMeta = $this->resolveSubscriptionExpiration($request, $plan, $organization);

            if ($subscription) {
                $attributes = [
                    'plan_id' => $plan ? $plan->id : null,
                    'status' => $subscriptionMeta['status'],
                ];
                if ($request->filled('valid_until')) {
                    $expiration = $subscriptionMeta['valid_until'];
                    $planChanged = (int) $subscription->plan_id !== (int) ($plan?->id);
                    $expirationChanged = ! $subscription->getRawOriginal('valid_until')
                        || ! $expiration->equalTo(Carbon::parse($subscription->getRawOriginal('valid_until'), 'UTC'));
                    if (($planChanged || $expirationChanged) && $request->has('subscription_version')
                        && ((int) $request->input('subscription_id') !== (int) $subscription->id
                            || (int) $request->input('subscription_version') !== (int) $subscription->period_version)) {
                        throw ValidationException::withMessages(['valid_until' => __('The subscription changed. Reload the page before trying again.')]);
                    }
                    if ($planChanged && $expiration->lte(now())) {
                        throw ValidationException::withMessages(['valid_until' => __('Choose an expiration date in the future.')]);
                    }
                    if ($expirationChanged) {
                        $attributes['valid_until'] = $expiration->toDateTimeString();
                        $attributes['expiry_notification_processed_at'] = null;
                    }
                }
                $subscription->update($attributes);
            } else {
                if ($request->filled('valid_until') && $subscriptionMeta['valid_until']->lte(now())) {
                    throw ValidationException::withMessages(['valid_until' => __('Choose an expiration date in the future.')]);
                }
                DB::transaction(fn () => Subscription::create([
                    'organization_id' => $organization->id,
                    'status' => $subscriptionMeta['status'],
                    'plan_id' => $plan ? $plan->id : null,
                    'start_date' => now(),
                    'valid_until' => $subscriptionMeta['valid_until'],
                ]));
            }

            return $organization;
        });
    }

    private function campaignPhoneMpsFromRequest(object $request, bool $useDefaultWhenMissing = false): ?int
    {
        if (! $useDefaultWhenMissing && ! $request->has('campaign_phone_mps')) {
            return null;
        }

        $value = $request->input('campaign_phone_mps');

        if ($value === null || $value === '') {
            return (int) config('campaigns.throttle.default_phone_mps', 20);
        }

        return (int) $value;
    }

    private function metadataWithCampaignPhoneMps(?string $currentMetadata, int $campaignPhoneMps): string
    {
        $metadata = json_decode((string) $currentMetadata, true);
        $metadata = is_array($metadata) ? $metadata : [];

        if (! isset($metadata['campaigns']) || ! is_array($metadata['campaigns'])) {
            $metadata['campaigns'] = [];
        }

        $metadata['campaigns']['phone_mps'] = $campaignPhoneMps;

        return json_encode($metadata);
    }

    private function resourceAccountOptions(?int $excludeOrganizationId = null, ?array $sharingPlanIds = null)
    {
        $sharingPlanIds ??= $this->resourceSharingPlanIds(
            SubscriptionPlan::query()
                ->whereNull('deleted_at')
                ->get(['id', 'metadata'])
        );

        if ($sharingPlanIds === []) {
            return collect();
        }

        return Organization::query()
            ->whereNull('billing_organization_id')
            ->when($excludeOrganizationId, fn ($query) => $query->where('id', '!=', $excludeOrganizationId))
            ->whereExists(function ($query) use ($sharingPlanIds): void {
                $query->selectRaw('1')
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.organization_id', 'organizations.id')
                    ->whereIn('subscriptions.plan_id', $sharingPlanIds)
                    ->whereRaw(
                        'subscriptions.id = (select max(`latest_subscriptions`.`id`) from `subscriptions` as `latest_subscriptions` where `latest_subscriptions`.`organization_id` = `organizations`.`id`)'
                    );
            })
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->values();
    }

    private function resourceSharingPlanIds($plans): array
    {
        return $plans
            ->filter(function (SubscriptionPlan $plan): bool {
                $metadata = json_decode((string) $plan->metadata, true);
                $metadata = is_array($metadata) ? $metadata : [];

                return (bool) ((int) ($metadata['resource_sharing_enabled'] ?? 0));
            })
            ->pluck('id')
            ->all();
    }

    private function resolveBillingOrganizationId(object $request, ?int $organizationId = null): ?int
    {
        if ($request->has('billing_organization_id')) {
            $billingOrganizationId = $request->input('billing_organization_id');

            return $billingOrganizationId ? (int) $billingOrganizationId : null;
        }

        if (! request()->routeIs('user.organization.store')) {
            return null;
        }

        $currentOrganizationId = session()->get('current_organization');
        if (! $currentOrganizationId || (int) $currentOrganizationId === $organizationId) {
            return null;
        }

        $usageService = new SubscriptionUsageService();
        $resourceAccountId = $usageService->resourceAccountId((int) $currentOrganizationId);

        return $usageService->resourceSharingEnabledForOrganization($resourceAccountId)
            ? $resourceAccountId
            : null;
    }

    private function resolveSubscriptionExpiration($request, ?SubscriptionPlan $plan, ?Organization $organization = null): array
    {
        $config = Setting::where('key', 'trial_period')->first();
        $trialDays = isset($config->value) && (int) $config->value > 0 ? (int) $config->value : 7;
        $hasTrialConfig = isset($config->value) && (int) $config->value > 0;
        $trialExpiration = now()->addDays($trialDays);
        $customExpiration = $request->filled('valid_until')
            ? Carbon::parse($request->input('valid_until'), $organization?->effectiveTimezone() ?? 'UTC')->utc() : null;
        $isTrialPlan = $request->plan === 'trial';
        $statusOverride = $request->input('subscription_status');

        $defaultExpiration = $plan && !$isTrialPlan
            ? match ($plan->period) {
                'yearly' => now()->addYear(),
                'monthly' => now()->addMonth(),
                default => $trialExpiration,
            }
            : $trialExpiration;

        return [
            'valid_until' => $customExpiration ?? $defaultExpiration,
            'status' => $this->resolveSubscriptionStatus($isTrialPlan, $hasTrialConfig, $statusOverride),
        ];
    }

    private function resolveSubscriptionStatus(bool $isTrialPlan, bool $hasTrialConfig, ?string $statusOverride): string
    {
        $allowed = ['trial', 'active', 'suspended'];
        $default = $isTrialPlan || $hasTrialConfig ? 'trial' : 'active';

        if ($statusOverride && in_array($statusOverride, $allowed, true)) {
            return $statusOverride;
        }

        return $default;
    }

    public function storeTransaction($request, $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $organization = Organization::where('uuid', $uuid)->firstOrFail();

            $modelClass = match ($request->type) {
                'credit' => BillingCredit::class,
                'debit' => BillingDebit::class,
                'payment' => BillingPayment::class,
            };

            $transactionData = [
                'organization_id' => $organization->id,
                'amount' => $request->amount,
            ];

            if (in_array($type, ['credit', 'debit'])) {
                $entryData['description'] = $request->description;
            }

            if ($type === 'payment') {
                $entryData['processor'] = $request->method;
            }

            $entry = $modelClass::create($entryData);

            $transaction = BillingTransaction::create([
                'organization_id' => $organization->id,
                'entity_type' => $request->type,
                'entity_id' => $entry->id,
                'description' => $request->type === 'payment' ? $request->method . ' Transaction' : $request->description,
                'amount' => $request->amount,
                'created_by' => auth()->user()->id
            ]);

            return $transaction;
        });
    }

    public function destroy($uuid)
    {
        // Find the organization by its UUID
        $organization = Organization::where('uuid', $uuid)->first();

        if ($organization) {
            // Delete all teams associated with the organization
            Team::where('organization_id', $organization->id)->delete();

            // Delete the organization
            $organization->delete();

            // Return true to indicate successful deletion
            return true;
        }

        // Return false if the organization does not exist
        return false;
    }
}
