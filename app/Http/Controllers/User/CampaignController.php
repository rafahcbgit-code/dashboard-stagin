<?php

namespace App\Http\Controllers\User;

use App\Exports\CampaignDetailsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreCampaign;
use App\Http\Resources\CampaignLogResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Services\CampaignService;
use App\Services\ContactFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CampaignController extends BaseController
{
    private $campaignService;

    public function __construct(CampaignService $campaignService)
    {
        $this->campaignService = $campaignService;
    }

    public function index(Request $request, $uuid = null)
    {
        $organizationId = session()->get('current_organization');
        $team = Team::where('organization_id', $organizationId)->where('user_id', auth()->user()->id)->first();

        if ($uuid == null) {
            $searchTerm = $request->query('search');
            $settings = Organization::where('id', $organizationId)->first();
            $rows = CampaignResource::collection(
                Campaign::with(['template'])
                    ->where('organization_id', $organizationId)
                    ->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%')
                            ->orWhereHas('template', function ($templateQuery) use ($searchTerm) {
                                $templateQuery->where('name', 'like', '%' . $searchTerm . '%');
                            });
                    })
                    ->latest()
                    ->paginate(10)
            );

            return Inertia::render('User/Campaign/Index', ['title' => __('Campaigns'), 'allowCreate' => true, 'rows' => $rows, 'filters' => request()->all(['search']), 'settings' => $settings, 'role' => $team->role]);
        } elseif ($uuid == 'create') {
            $data['settings'] = Organization::where('id', $organizationId)->first();
            $data['templates'] = Template::where('organization_id', $organizationId)
                ->where('deleted_at', null)
                ->where('status', 'APPROVED')
                ->get();

            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $data['contactFields'] = $contactFieldService->get($request);

            $data['contactGroups'] = ContactGroup::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereHas('contacts')
                ->get();

            $data['title'] = __('Create campaign');

            //dd($data['templates']);
            return Inertia::render('User/Campaign/Create', $data);
        } else {
            $data['campaign'] = Campaign::with('contactGroup', 'template')->where('uuid', $uuid)->first();
            if ($data['campaign']) {
                $counts = $data['campaign']->getCounts();
                $data['campaign']['total_message_count'] = $counts->total_message_count ?? 0;
                $data['campaign']['total_sent_count'] = $counts->total_sent_count ?? 0;
                $data['campaign']['total_delivered_count'] = $counts->total_delivered_count ?? 0;
                $data['campaign']['total_failed_count'] = $counts->total_failed_count ?? 0;
                $data['campaign']['total_read_count'] = $counts->total_read_count ?? 0;
            } else {
                $data['campaign']['total_message_count'] = 0;
                $data['campaign']['total_sent_count'] = 0;
                $data['campaign']['total_delivered_count'] = 0;
                $data['campaign']['total_read_count'] = 0;
                $data['campaign']['total_failed_count'] = 0;
            }

            $data['filters'] = request()->all(['search']);

            $searchTerm = $request->query('search');
            $data['rows'] = CampaignLogResource::collection(
                CampaignLog::with('contact', 'chat.logs')
                    ->where('campaign_id', $data['campaign']->id)
                    ->where(function ($query) use ($searchTerm) {
                        $query->whereHas('contact', function ($contactQuery) use ($searchTerm) {
                            $contactQuery->where('first_name', 'like', '%' . $searchTerm . '%')
                                ->orWhere('last_name', 'like', '%' . $searchTerm . '%')
                                ->orWhere('phone', 'like', '%' . $searchTerm . '%');
                        });
                    })
                    ->orderBy('id')
                    ->paginate(10)
            );
            $data['title'] = __('View campaign');

            // Task 2.5: AC-2.5.4 - Pass Pusher settings for real-time progress updates
            $pusherKeys = ['pusher_app_key', 'pusher_app_cluster'];
            $data['pusherSettings'] = Setting::whereIn('key', $pusherKeys)->pluck('value', 'key')->toArray();
            $data['organizationId'] = $organizationId;

            return Inertia::render('User/Campaign/View', $data);
        }
    }

    public function store(StoreCampaign $request)
    {
        $this->campaignService->store($request);

        return Redirect::route('campaigns')->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Campaign created successfully!'),
            ]
        );
    }

    public function export($uuid = null)
    {
        return Excel::download(new CampaignDetailsExport($uuid), 'campaign.csv');
    }

    public function delete($uuid)
    {
        $this->campaignService->destroy($uuid);

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Row deleted successfully!'),
            ]
        );
    }

    /**
     * Task 2.6: AC-2.6.1, AC-2.6.5, AC-2.6.6, AC-2.6.7 - Cancel a running campaign
     *
     * Cancels an ongoing campaign, marking all pending logs as cancelled.
     * In-flight messages (already dispatched) will check status and skip gracefully.
     */
    public function cancel(string $uuid): JsonResponse
    {
        $organizationId = session()->get('current_organization');

        // AC-2.6.6: Validate campaign exists and belongs to current organization
        $campaign = Campaign::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $campaign) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        // AC-2.6.7: Only allow cancel if ongoing
        if ($campaign->status !== 'ongoing') {
            return response()->json([
                'error' => 'Campaign can only be cancelled when ongoing',
                'current_status' => $campaign->status,
            ], 400);
        }

        // AC-2.6.2: Update campaign status to cancelled
        $campaign->update(['status' => 'cancelled']);

        // AC-2.6.3: Bulk update all pending CampaignLog records to cancelled
        $cancelledCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        // AC-2.6.5: Calculate final stats
        $stats = CampaignLog::where('campaign_id', $campaign->id)
            ->selectRaw("
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return response()->json([
            'status' => 'cancelled',
            'message' => 'Campaign cancellation initiated',
            'stats' => [
                'sent' => (int) $stats->sent,
                'cancelled' => (int) $stats->cancelled,
                'failed' => (int) $stats->failed,
            ],
        ]);
    }
}
