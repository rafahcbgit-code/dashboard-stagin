<?php

namespace App\Services;

use App\Http\Resources\OrganizationsResource;
use App\Http\Resources\BillingResource;
use App\Http\Resources\UserResource;
use App\Models\BillingCredit;
use App\Models\BillingDebit;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Str;
use Propaganistas\LaravelPhone\PhoneNumber;
use Carbon\Carbon;

class OrganizationService
{
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
                Organization::find($organizationId)?->metadata ?? '{}',
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

        if ($uuid === null) {
            $result['organization'] = null;
            $result['billing'] = null;
            $result['users'] = null;

            return $result;
        }

        $organization = Organization::with('subscription.plan')->where('uuid', $uuid)->first();
        $users = (new User)->listAll('user', $request->query('search'), $organization->id);
        $billing = (new BillingTransaction)->listAll($request->query('search'), $organization->id);

        $result['organization'] = $organization;
        $result['billing'] = BillingResource::collection($billing);
        $result['users'] = UserResource::collection($users);

        return $result;
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
        $organization = Organization::where('uuid', $uuid)->firstOrFail();

        $organization->update([
            'name' => $request->input('name'),
            'address' => json_encode([
                'street' => $request->street,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
                'country' => $request->country,
            ]),
        ]);

        $subscription = Subscription::where('organization_id', $organization->id)->first();
        $plan = SubscriptionPlan::where('uuid', $request->plan)->first();
        $subscriptionMeta = $this->resolveSubscriptionExpiration($request, $plan);

        if ($subscription) {
            $subscription->update([
                'plan_id' => $plan ? $plan->id : null,
                'status' => $subscriptionMeta['status'],
                'start_date' => now(),
                'valid_until' => $subscriptionMeta['valid_until'],
            ]);
        } else {
            Subscription::create([
                'organization_id' => $organization->id,
                'status' => $subscriptionMeta['status'],
                'plan_id' => $plan ? $plan->id : null,
                'start_date' => now(),
                'valid_until' => $subscriptionMeta['valid_until'],
            ]);
        }

        return $organization;
    }

    private function resolveSubscriptionExpiration($request, ?SubscriptionPlan $plan): array
    {
        $config = Setting::where('key', 'trial_period')->first();
        $trialDays = isset($config->value) && (int) $config->value > 0 ? (int) $config->value : 7;
        $hasTrialConfig = isset($config->value) && (int) $config->value > 0;
        $trialExpiration = now()->addDays($trialDays);
        $customExpiration = $request->filled('valid_until') ? Carbon::parse($request->input('valid_until')) : null;
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
