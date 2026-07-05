<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Organization;
use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ResourceAddonController extends BaseController
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $addons = ResourceAddon::query()
            ->with('organization:id,name,uuid')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('resource', 'like', '%' . $search . '%')
                    ->orWhereHas('organization', function ($organizationQuery) use ($search): void {
                        $organizationQuery->where('name', 'like', '%' . $search . '%');
                    });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/ResourceAddons/Index', [
            'title' => __('Resource add-ons'),
            'rows' => $addons,
            'filters' => $request->all(['search']),
            'organizations' => Organization::query()
                ->leftJoin('organizations as billing_organizations', 'organizations.billing_organization_id', '=', 'billing_organizations.id')
                ->leftJoinSub(
                    Subscription::query()
                        ->select('organization_id', DB::raw('MAX(id) as latest_subscription_id'))
                        ->groupBy('organization_id'),
                    'latest_subscriptions',
                    function ($join): void {
                        $join->on(DB::raw('COALESCE(organizations.billing_organization_id, organizations.id)'), '=', 'latest_subscriptions.organization_id');
                    }
                )
                ->leftJoin('subscriptions', 'subscriptions.id', '=', 'latest_subscriptions.latest_subscription_id')
                ->orderBy('organizations.name')
                ->get([
                    'organizations.id',
                    'organizations.name',
                    'organizations.billing_organization_id',
                    'billing_organizations.name as billing_organization_name',
                    'subscriptions.valid_until as subscription_valid_until',
                ]),
            'resources' => [
                ['value' => SubscriptionUsageService::TEMPLATE_MESSAGES, 'label' => __('Additional Template Messages')],
                ['value' => SubscriptionUsageService::TEAM_MEMBERS, 'label' => __('Additional Team Members')],
                ['value' => SubscriptionUsageService::CONTACTS, 'label' => __('Additional Contacts')],
                ['value' => SubscriptionUsageService::CONVERSATIONS, 'label' => __('Additional Conversations')],
                ['value' => SubscriptionUsageService::ORGANIZATIONS, 'label' => __('Additional Organizations')],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'resource' => ['required', 'in:template_messages,team_members,contacts,conversations,organizations'],
            'quantity' => ['required', 'integer', 'min:1'],
            'valid_until' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['organization_id'] = (new SubscriptionUsageService())
            ->resourceAccountId((int) $validated['organization_id']);

        $cycleEndsAt = Subscription::where('organization_id', $validated['organization_id'])
            ->latest('id')
            ->value('valid_until');

        if ($cycleEndsAt) {
            $requestedValidUntil = Carbon::parse($validated['valid_until']);
            $cycleEndsAt = Carbon::parse($cycleEndsAt);
            $validated['valid_until'] = $requestedValidUntil->greaterThan($cycleEndsAt)
                ? $cycleEndsAt
                : $requestedValidUntil;
        }

        ResourceAddon::create([
            ...$validated,
            'granted_by' => auth()->id(),
        ]);

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Resource add-on granted successfully.'),
        ]);
    }

    public function destroy(ResourceAddon $resource_addon)
    {
        $resource_addon->delete();

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Resource add-on deleted successfully.'),
        ]);
    }
}
