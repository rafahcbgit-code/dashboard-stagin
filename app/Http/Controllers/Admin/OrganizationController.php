<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreOrganization;
use App\Http\Requests\ManageSubscriptionPeriod;
use App\Http\Requests\ExportOrganizationChatsRequest;
use App\Exports\OrganizationChatsExport;
use App\Models\Organization;
use App\Jobs\SyncWhatsappNumberStatusJob;
use App\Services\OrganizationService;
use App\Services\SubscriptionPeriodService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;

class OrganizationController extends BaseController
{
    private $organizationService;
    private $role;

    /**
     * OrganizationController constructor.
     *
     * @param UserService $organizationService
     */
    public function __construct()
    {
        $this->organizationService = new OrganizationService();
    }

    /**
     * Display a listing of organizations.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/Organization/Index', [
            'title' => __('Organizations'),
            'allowCreate' => true,
            'rows' => $this->organizationService->get($request),
            'filters' => $request->all(),
        ]);
    }

    /**
     * Queue a WhatsApp number status sync for all configured organizations.
     */
    public function syncWhatsappNumberStatuses()
    {
        SyncWhatsappNumberStatusJob::dispatch()
            ->onConnection('redis')
            ->onQueue('default');

        return back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('WhatsApp number status sync has been queued successfully!'),
            ]
        );
    }

    /**
     * Return organizations for CRM integrations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function CRMOrganizations(Request $request)
    {
        $organizations = $this->organizationService->CRMOrganizations($request);

        return response()->json([
            'success' => true,
            'data' => $organizations->items(),
            'meta' => [
                'filters' => array_filter($request->only([
                    'search',
                    'subscription_status',
                    'created_from',
                    'created_to',
                    'per_page',
                    'cursor',
                ]), fn ($value) => $value !== null && $value !== ''),
                'pagination' => [
                    'per_page' => $organizations->perPage(),
                    'next_cursor' => $organizations->nextCursor()?->encode(),
                    'previous_cursor' => $organizations->previousCursor()?->encode(),
                    'next_page_url' => $organizations->nextPageUrl(),
                    'previous_page_url' => $organizations->previousPageUrl(),
                    'has_more_pages' => $organizations->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * Display the specified organization.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $uuid = NULL, $mode = NULL)
    {
        $res = $this->organizationService->getByUuid($request, $uuid);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Organization'),
            'organization' => $res['organization'],
            'users' => $res['users'],
            'plans' => $res['plans'],
            'campaignRateConfig' => $this->campaignRateConfig(),
            'resourceAccounts' => $res['resource_accounts'],
            'invoices' => $res['billing'],
            'subscriptionEvents' => $res['subscription_events'],
            'subscriptionArchives' => \Illuminate\Support\Facades\DB::table('subscription_period_archives')
                ->where('organization_id', $res['organization']->id)->orderByDesc('id')->paginate(20, ['*'], 'period_page')->withQueryString(),
            'subscriptionTimezone' => app(SubscriptionPeriodService::class)->timezone($res['organization']),
            'subscriptionExpiration' => ($expiration = $res['organization']->subscription?->getRawOriginal('valid_until'))
                ? \Carbon\Carbon::parse($expiration, 'UTC')->setTimezone($res['organization']->effectiveTimezone())->toDateTimeString() : null,
            'mode' => $mode,
            'filters' => $request->all()
        ]);
    }

    /**
     * Display Form
     *
     * @param $request
     */
    public function create(Request $request)
    {
        $res = $this->organizationService->getByUuid($request);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Create Org.'),
            'organization' => $res['organization'],
            'users' => $res['users'],
            'plans' => $res['plans'],
            'campaignRateConfig' => $this->campaignRateConfig(),
            'resourceAccounts' => $res['resource_accounts'],
            'invoices' => $res['billing'],
            'subscriptionEvents' => $res['subscription_events'],
            'filters' => $request->all()
        ]);
    }

    /**
     * Store a newly created organization.
     *
     * @param Request $request
     */
    public function store(StoreOrganization $request)
    {
        $this->organizationService->store($request);

        return redirect('/admin/organizations')->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Organization created successfully!')
            ]
        );
    }

    /**
     * Update the specified organization.
     *
     * @param Request $request
     */
    public function update(StoreOrganization $request, $uuid)
    {
        $this->organizationService->update($request, $uuid);

        return redirect('/admin/organizations/' . $uuid)->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Organization updated successfully!')
            ]
        );
    }

    public function exportChats(ExportOrganizationChatsRequest $request, string $uuid): StreamedResponse
    {
        $organization = Organization::where('uuid', $uuid)->firstOrFail();
        $export = new OrganizationChatsExport(
            $organization,
            $request->string('type')->toString(),
            $request->input('date_from'),
            $request->input('date_to'),
        );

        return response()->streamDownload(function () use ($export) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $export->headers(), ',', '"', '\\');

            foreach ($export->query()->cursor() as $row) {
                fputcsv($output, array_map(
                    static fn ($value) => self::csvValue($value),
                    (array) $row
                ), ',', '"', '\\');
            }

            fclose($output);
        }, $export->filename(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function csvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'{$value}" : $value;
    }

    public function extendSubscription(ManageSubscriptionPeriod $request, string $uuid, SubscriptionPeriodService $periods)
    {
        $periods->manage($uuid, 'extend', $request->validated(), (int) $request->user('admin')->id);
        return back()->with('status', ['type' => 'success', 'message' => __('Subscription extended successfully.')]);
    }

    public function renewSubscription(ManageSubscriptionPeriod $request, string $uuid, SubscriptionPeriodService $periods)
    {
        $periods->manage($uuid, 'renew', $request->validated(), (int) $request->user('admin')->id);
        return back()->with('status', ['type' => 'success', 'message' => __('Subscription renewed and previous usage archived successfully.')]);
    }

    /**
     * Remove the specified organization.
     *
     * @param String $uuid
     */
    public function destroy($uuid)
    {
        $query = $this->organizationService->destroy($uuid);

        return back()->with(
            'status',
            [
                'type' => $query ? 'success' : 'error',
                'message' => $query ? __('Organization deleted successfully!') : __('This organization does not exist!')
            ]
        );
    }

    private function campaignRateConfig(): array
    {
        return [
            'default_phone_mps' => (int) config('campaigns.throttle.default_phone_mps', 20),
            'max_phone_mps' => (int) config('campaigns.throttle.max_phone_mps', 80),
        ];
    }
}
