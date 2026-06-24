<?php

namespace App\Http\Controllers\User;

use App\Exports\CampaignDetailsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreCampaign;
use App\Http\Resources\CampaignLogResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\Template;
use App\Services\CampaignService;
use App\Services\ContactFieldService;
use App\Services\SubscriptionUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CampaignController extends BaseController
{
    private $campaignService;

    public function __construct(CampaignService $campaignService)
    {
        $this->campaignService = $campaignService;
    }

    private function resolveDateRange(Request $request): array
    {
        try {
            $start = $request->query('start_date')
                ? Carbon::parse($request->query('start_date'))->startOfDay()
                : Carbon::now()->startOfDay()->subDays(29);
        } catch (\Throwable $e) {
            $start = Carbon::now()->startOfDay()->subDays(29);
        }

        try {
            $end = $request->query('end_date')
                ? Carbon::parse($request->query('end_date'))->endOfDay()
                : Carbon::now()->endOfDay();
        } catch (\Throwable $e) {
            $end = Carbon::now()->endOfDay();
        }

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    private function getCampaignAnalyticsForOrganization(int $organizationId, Carbon $start, Carbon $end): array
    {
        $stats = DB::table('campaign_logs')
            ->join('campaigns', 'campaign_logs.campaign_id', '=', 'campaigns.id')
            ->leftJoin('chats as chat', 'chat.id', '=', 'campaign_logs.chat_id')
            ->where('campaigns.organization_id', $organizationId)
            ->whereNull('campaigns.deleted_at')
            ->whereBetween('campaign_logs.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw('
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("accepted", "sent", "delivered", "read") THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("delivered", "read") THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "read" THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN campaign_logs.status IN ("failed", "permanently_failed") THEN 1 ELSE 0 END) +
                    SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN campaign_logs.status = "ongoing" THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN campaign_logs.status = "pending" THEN 1 ELSE 0 END) as queued
            ')
            ->first();

        return [
            'sent' => (int) ($stats->sent ?? 0),
            'delivered' => (int) ($stats->delivered ?? 0),
            'read' => (int) ($stats->read_count ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'processing' => (int) ($stats->processing ?? 0),
            'queued' => (int) ($stats->queued ?? 0),
        ];
    }

    private function mapCampaignDeliveryStatus(string $campaignLogStatus, ?string $chatStatus): string
    {
        if (in_array($campaignLogStatus, ['failed', 'permanently_failed'], true)) {
            return 'failed';
        }

        if ($campaignLogStatus === 'success' && $chatStatus === 'failed') {
            return 'failed';
        }

        if ($campaignLogStatus === 'success' && $chatStatus === 'read') {
            return 'read';
        }

        if ($campaignLogStatus === 'success' && $chatStatus === 'delivered') {
            return 'delivered';
        }

        if ($campaignLogStatus === 'success' && in_array($chatStatus, ['accepted', 'sent'], true)) {
            return 'sent';
        }

        if ($campaignLogStatus === 'ongoing') {
            return 'processing';
        }

        if ($campaignLogStatus === 'pending') {
            return 'queued';
        }

        if ($campaignLogStatus === 'cancelled') {
            return 'cancelled';
        }

        return $campaignLogStatus ?: 'unknown';
    }

    public function index(Request $request, $uuid = null)
    {
        $organizationId = session()->get('current_organization');
        $team = Team::where('organization_id', $organizationId)->where('user_id', auth()->user()->id)->first();

        if ($uuid == null) {
            $searchTerm = $request->query('search');
            $settings = Organization::where('id', $organizationId)->first();
            [$start, $end] = $this->resolveDateRange($request);

            // show stats only during active trial
            $showStats = Subscription::isActiveTrialForOrg($organizationId);

            $overallDelivered = $showStats ? Campaign::overallDeliveryCountForOrg($organizationId) : null;
            $campaignLimit = $showStats ? Campaign::campaignMessagesLimit() : null;
            $campaignAnalytics = $this->getCampaignAnalyticsForOrganization((int) $organizationId, $start, $end);

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
        
            return Inertia::render('User/Campaign/Index', [
                'title' => __('Campaigns'),
                'allowCreate' => true,
                'rows' => $rows,
                'filters' => request()->all(['search', 'start_date', 'end_date']),
                'settings' => $settings,
                'role' => $team->role,
                'overall_delivery_count' => $overallDelivered,
                'campaign_messages_limit' => $campaignLimit,
                'trial' => $showStats,
                'campaign_analytics' => $campaignAnalytics,
                'initial_start_date' => $start->toDateString(),
                'initial_end_date' => $end->toDateString(),
            ]);
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
                ->withCount(['contacts as contacts_count' => function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                }])
                ->get();

            $usageService = new SubscriptionUsageService();
            $data['resourceUsage'] = [
                'template_messages' => $usageService->summary((int) $organizationId, SubscriptionUsageService::TEMPLATE_MESSAGES),
                'conversations' => $usageService->summary((int) $organizationId, SubscriptionUsageService::CONVERSATIONS),
            ];
            $data['totalContacts'] = Contact::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->count();

            $data['title'] = __('Create campaign');

            return Inertia::render('User/Campaign/Create', $data);
        } else {
            $campaign = Campaign::with('contactGroup', 'template')
                ->where('uuid', $uuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (! $campaign) {
                return Redirect::route('campaigns')->with(
                    'status',
                    [
                        'type' => 'error',
                        'message' => __('Campaign not found.'),
                    ]
                );
            }

            $counts = $campaign->getCounts();
            $campaign['total_message_count'] = $counts->total_message_count ?? 0;
            $campaign['total_sent_count'] = $counts->total_sent_count ?? 0;
            $campaign['total_delivered_count'] = $counts->total_delivered_count ?? 0;
            $campaign['total_failed_count'] = $counts->total_failed_count ?? 0;
            $campaign['total_read_count'] = $counts->total_read_count ?? 0;
            $campaign['rate_limited_failed_count'] = CampaignLog::where('campaign_id', $campaign->id)
                ->where(function ($query) {
                    $query->whereIn('status', ['failed', 'permanently_failed'])
                        ->where(function ($q) {
                            $q->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                              ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
                        });
                })
                ->orWhere(function ($query) {
                    $query->where('status', 'success')
                        ->whereNull('chat_id')
                        ->where(function ($q) {
                            $q->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                              ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
                        });
                })
                ->count();
            $data['campaign'] = $campaign;

            $data['filters'] = request()->all(['search']);

            $searchTerm = $request->query('search');
            $data['rows'] = CampaignLogResource::collection(
                CampaignLog::with('contact', 'chat.logs')
                    ->where('campaign_id', $campaign->id)
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

    public function exportCampaignStatuses(Request $request): StreamedResponse
    {
        $organizationId = (int) session()->get('current_organization');
        [$start, $end] = $this->resolveDateRange($request);
        $searchTerm = $request->query('search');
        $status = strtolower((string) $request->query('status', ''));

        $query = DB::table('campaign_logs')
            ->join('campaigns', 'campaign_logs.campaign_id', '=', 'campaigns.id')
            ->leftJoin('contacts', 'campaign_logs.contact_id', '=', 'contacts.id')
            ->leftJoin('chats as chat', 'campaign_logs.chat_id', '=', 'chat.id')
            ->where('campaigns.organization_id', $organizationId)
            ->whereNull('campaigns.deleted_at')
            ->whereBetween('campaign_logs.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select([
                'campaign_logs.id',
                'campaign_logs.status as campaign_log_status',
                'campaign_logs.created_at',
                'campaign_logs.updated_at',
                'campaigns.uuid as campaign_uuid',
                'campaigns.name as campaign_name',
                'contacts.first_name as contact_first_name',
                'contacts.last_name as contact_last_name',
                'contacts.phone as contact_phone',
                'chat.status as chat_status',
            ])
            ->orderBy('campaign_logs.id');

        if (is_string($searchTerm) && trim($searchTerm) !== '') {
            $term = trim($searchTerm);
            $query->where(function ($q) use ($term) {
                $q->where('campaigns.name', 'like', '%' . $term . '%')
                    ->orWhere('contacts.first_name', 'like', '%' . $term . '%')
                    ->orWhere('contacts.last_name', 'like', '%' . $term . '%')
                    ->orWhere('contacts.phone', 'like', '%' . $term . '%');
            });
        }

        $allowedStatuses = ['sent', 'delivered', 'read', 'failed', 'processing', 'queued', 'cancelled'];
        if (in_array($status, $allowedStatuses, true)) {
            $query->where(function ($q) use ($status) {
                if ($status === 'failed') {
                    $q->whereIn('campaign_logs.status', ['failed', 'permanently_failed'])
                        ->orWhere(function ($inner) {
                            $inner->where('campaign_logs.status', 'success')
                                ->where('chat.status', 'failed');
                        });
                    return;
                }

                if ($status === 'read') {
                    $q->where('campaign_logs.status', 'success')
                        ->where('chat.status', 'read');
                    return;
                }

                if ($status === 'delivered') {
                    $q->where('campaign_logs.status', 'success')
                        ->where('chat.status', 'delivered');
                    return;
                }

                if ($status === 'sent') {
                    $q->where('campaign_logs.status', 'success')
                        ->whereIn('chat.status', ['accepted', 'sent']);
                    return;
                }

                if ($status === 'processing') {
                    $q->where('campaign_logs.status', 'ongoing');
                    return;
                }

                if ($status === 'queued') {
                    $q->where('campaign_logs.status', 'pending');
                    return;
                }

                if ($status === 'cancelled') {
                    $q->where('campaign_logs.status', 'cancelled');
                }
            });
        }

        $fileName = 'campaign_statuses_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        $callback = function () use ($query, $start, $end) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'campaign_uuid',
                'campaign_name',
                'contact_name',
                'phone',
                'final_status',
                'campaign_log_status',
                'chat_status',
                'created_at',
                'updated_at',
                'range_start_date',
                'range_end_date',
            ]);

            foreach ($query->cursor() as $row) {
                $fullName = trim(($row->contact_first_name ?? '') . ' ' . ($row->contact_last_name ?? ''));
                $finalStatus = $this->mapCampaignDeliveryStatus(
                    (string) ($row->campaign_log_status ?? ''),
                    $row->chat_status ? (string) $row->chat_status : null
                );

                fputcsv($handle, [
                    $row->campaign_uuid,
                    $row->campaign_name,
                    $fullName,
                    $row->contact_phone,
                    $finalStatus,
                    $row->campaign_log_status,
                    $row->chat_status,
                    $row->created_at,
                    $row->updated_at,
                    $start->toDateString(),
                    $end->toDateString(),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
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

    public function resendFailed(string $uuid): JsonResponse
    {
        $organizationId = session()->get('current_organization');

        $campaign = Campaign::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        if (! in_array($campaign->status, ['completed', 'cancelled'])) {
            return response()->json([
                'error' => 'Can only resend failed messages for completed or cancelled campaigns',
                'current_status' => $campaign->status,
            ], 400);
        }

        $rateLimitedQuery = CampaignLog::where('campaign_id', $campaign->id)
            ->where(function ($query) {
                // Failed or permanently_failed logs with rate limit error
                $query->whereIn('status', ['failed', 'permanently_failed'])
                    ->where(function ($q) {
                        $q->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                          ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
                    });
            })
            ->orWhere(function ($query) {
                // Success logs with rate limit error in metadata (bug: marked success incorrectly)
                $query->where('status', 'success')
                    ->whereNull('chat_id')
                    ->where(function ($q) {
                        $q->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                          ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
                    });
            });

        $failedCount = $rateLimitedQuery->count();

        if ($failedCount === 0) {
            return response()->json(['error' => 'No rate-limited messages to resend'], 400);
        }

        $rateLimitedQuery->update([
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        $campaign->update(['status' => 'ongoing']);

        return response()->json([
            'status' => 'ongoing',
            'message' => 'Resending rate-limited messages',
            'failed_count' => $failedCount,
        ]);
    }
}
