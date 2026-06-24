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
                ->leftJoinSub(
                    Subscription::query()
                        ->select('organization_id', DB::raw('MAX(id) as latest_subscription_id'))
                        ->groupBy('organization_id'),
                    'latest_subscriptions',
                    'organizations.id',
                    '=',
                    'latest_subscriptions.organization_id'
                )
                ->leftJoin('subscriptions', 'subscriptions.id', '=', 'latest_subscriptions.latest_subscription_id')
                ->orderBy('name')
                ->get([
                    'organizations.id',
                    'organizations.name',
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

        if ($validated['resource'] === SubscriptionUsageService::ORGANIZATIONS) {
            $validated['organization_id'] = $this->primaryOwnerOrganizationId((int) $validated['organization_id'])
                ?: $validated['organization_id'];
        }

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

    private function primaryOwnerOrganizationId(int $organizationId): ?int
    {
        $ownerUserId = DB::table('teams')
            ->where('organization_id', $organizationId)
            ->where('role', 'owner')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('user_id');

        if (! $ownerUserId) {
            return null;
        }

        return DB::table('teams')
            ->join('organizations', 'teams.organization_id', '=', 'organizations.id')
            ->where('teams.user_id', $ownerUserId)
            ->where('teams.role', 'owner')
            ->whereNull('teams.deleted_at')
            ->whereNull('organizations.deleted_at')
            ->orderBy('organizations.created_at')
            ->orderBy('organizations.id')
            ->value('teams.organization_id');
    }
}
