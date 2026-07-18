<?php

namespace App\Http\Controllers\User;

use App\Exports\CampaignDetailsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreCampaign;
use App\Http\Resources\CampaignLogResource;
use App\Http\Resources\CampaignResource;
use App\Jobs\ProcessCampaignMessagesJob;
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
use App\Services\CampaignStatsService;
use App\Services\ContactFieldService;
use App\Services\SubscriptionUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
        $cacheKey = sprintf(
            'campaign_analytics:%d:%s:%s',
            $organizationId,
            $start->format('YmdHis'),
            $end->format('YmdHis')
        );

        $stats = Cache::remember($cacheKey, 30, function () use ($organizationId, $start, $end) {
            return DB::table('campaign_stats')
                ->join('campaigns', 'campaign_stats.campaign_id', '=', 'campaigns.id')
                ->where('campaigns.organization_id', $organizationId)
                ->whereNull('campaigns.deleted_at')
                ->whereBetween('campaigns.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
                ->selectRaw('
                    SUM(campaign_stats.total_sent_count) as sent,
                    SUM(campaign_stats.total_delivered_count) as delivered,
                    SUM(campaign_stats.total_read_count) as read_count,
                    SUM(campaign_stats.total_failed_count) as failed,
                    SUM(campaign_stats.processing_count) as processing,
                    SUM(campaign_stats.queued_count) as queued
                ')
                ->first();
        });

        return [
            'sent' => (int) ($stats->sent ?? 0),
            'delivered' => (int) ($stats->delivered ?? 0),
            'read' => (int) ($stats->read_count ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'processing' => (int) ($stats->processing ?? 0),
            'queued' => (int) ($stats->queued ?? 0),
        ];
    }

    private function rateLimitedCampaignLogsQuery(int $campaignId)
    {
        $query = CampaignLog::where('campaign_id', $campaignId)
            ->where(function ($query) {
                $query->whereIn('status', ['failed', 'permanently_failed'])
                    ->orWhere(function ($query) {
                        $query->where('status', 'success')
                            ->whereNull('chat_id');
                    });
            });

        if ($this->campaignLogsRateLimitErrorColumnExists()) {
            return $query->where('rate_limit_error_code', '130429');
        }

        return $query->where(function ($query) {
            $query->whereRaw("JSON_EXTRACT(metadata, '$.data.error.code') = 130429")
                ->orWhereRaw("JSON_EXTRACT(metadata, '$.error.code') = 130429");
        });
    }

    private function campaignLogsRateLimitErrorColumnExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = Schema::hasColumn('campaign_logs', 'rate_limit_error_code');
        }

        return $exists;
    }

    private function paginateCampaignLogs(Campaign $campaign, ?string $searchTerm): LengthAwarePaginator
    {
        $perPage = 10;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $searchTerm = trim((string) $searchTerm);

        if ($searchTerm !== '') {
            $query = CampaignLog::with([
                'contact:id,first_name,last_name,phone',
                'chat:id,status,created_at',
                'chat.logs:id,chat_id,metadata,created_at',
            ])
                ->select(['id', 'campaign_id', 'contact_id', 'chat_id', 'status', 'created_at', 'updated_at', 'retry_count'])
                ->where('campaign_id', $campaign->id)
                ->orderBy('id');

            $query->whereHas('contact', function ($contactQuery) use ($searchTerm) {
                $like = '%' . $searchTerm . '%';

                $contactQuery->where(function ($query) use ($like) {
                    $query->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            });

            return $query->paginate($perPage)->withQueryString();
        }

        $total = (int) ($campaign->getCounts()->total_message_count ?? 0);
        $items = CampaignLog::with([
                'contact:id,first_name,last_name,phone',
                'chat:id,status,created_at',
                'chat.logs:id,chat_id,metadata,created_at',
            ])
            ->select(['id', 'campaign_id', 'contact_id', 'chat_id', 'status', 'created_at', 'updated_at', 'retry_count'])
            ->forceIndex('idx_campaign_logs_campaign_id_id')
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
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
        $userId = (int) auth()->user()->id;
        $teamRole = Cache::remember(
            "team-role:{$organizationId}:{$userId}",
            now()->addMinutes(5),
            fn () => Team::where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->value('role')
        );

        if ($uuid == null) {
            $searchTerm = trim((string) $request->query('search'));
            $settings = Organization::where('id', $organizationId)->first();
            [$start, $end] = $this->resolveDateRange($request);

            // show stats only during active trial
            $showStats = Subscription::isActiveTrialForOrg($organizationId);

            $overallDelivered = $showStats ? Campaign::overallDeliveryCountForOrg($organizationId) : null;
            $campaignLimit = $showStats ? Campaign::campaignMessagesLimit() : null;
            $campaignAnalytics = $this->getCampaignAnalyticsForOrganization((int) $organizationId, $start, $end);

            $campaigns = Campaign::with(['template', 'contactGroup'])
                ->where('organization_id', $organizationId)
                ->where('deleted_at', null)
                ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                    $query->where(function ($query) use ($searchTerm) {
                        $like = '%' . $searchTerm . '%';

                        $query->where('name', 'like', $like)
                            ->orWhereHas('template', function ($templateQuery) use ($like) {
                                $templateQuery->where('name', 'like', $like);
                            });
                        });
                })
                ->latest()
                ->paginate(10);

            $countsByCampaignId = Campaign::aggregateCountsForCampaignIds(
                $campaigns->getCollection()->pluck('id')
            );

            $campaigns->getCollection()->each(function (Campaign $campaign) use ($countsByCampaignId): void {
                $campaign->setPrecomputedCounts($countsByCampaignId->get($campaign->id));
            });

            $rows = CampaignResource::collection($campaigns);
        
            return Inertia::render('User/Campaign/Index', [
                'title' => __('Campaigns'),
                'allowCreate' => true,
                'rows' => $rows,
                'filters' => request()->all(['search', 'start_date', 'end_date']),
                'settings' => $settings,
                'role' => $teamRole,
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
                ->where('contacts_count', '>', 0)
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
            $campaign['rate_limited_failed_count'] = $this->rateLimitedCampaignLogsQuery($campaign->id)->count();
            $data['campaign'] = $campaign;

            $data['filters'] = request()->all(['search']);

            $searchTerm = $request->query('search');
            $data['rows'] = CampaignLogResource::collection(
                $this->paginateCampaignLogs($campaign, $searchTerm)
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
     * Update the scheduled time for a campaign that has not started yet.
     */
    public function updateSchedule(Request $request, string $uuid): JsonResponse
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'time' => ['required', 'date_format:Y-m-d\\TH:i'],
        ]);

        $campaign = Campaign::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (! $campaign) {
            return response()->json(['error' => __('Campaign not found')], 404);
        }

        if ($campaign->status !== 'scheduled') {
            return response()->json([
                'error' => __('The scheduled time can only be changed while the campaign is scheduled.'),
                'current_status' => $campaign->status,
            ], 400);
        }

        $organization = Organization::find($organizationId);
        $metadata = json_decode($organization?->metadata ?? '{}', true);
        $timezone = is_array($metadata) ? ($metadata['timezone'] ?? 'UTC') : 'UTC';

        try {
            $scheduledAt = Carbon::createFromFormat('Y-m-d\\TH:i', $validated['time'], $timezone)->utc();
        } catch (\Throwable $exception) {
            return response()->json(['errors' => ['time' => [__('The scheduled time is invalid.')]]], 422);
        }

        if ($scheduledAt->lte(Carbon::now('UTC'))) {
            return response()->json(['errors' => ['time' => [__('The scheduled time must be in the future.')]]], 422);
        }

        // Check the status again in the update query so a campaign that starts in
        // parallel cannot have its schedule changed after it becomes ongoing.
        $updated = Campaign::whereKey($campaign->id)
            ->where('status', 'scheduled')
            ->update(['scheduled_at' => $scheduledAt]);

        if ($updated !== 1) {
            $campaign->refresh();

            return response()->json([
                'error' => __('The campaign is no longer scheduled.'),
                'current_status' => $campaign->status,
            ], 409);
        }

        $campaign->refresh();

        return response()->json([
            'message' => __('Scheduled time updated successfully.'),
            'scheduled_at' => $campaign->scheduled_at,
        ]);
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
        $campaign->update([
            'status' => 'cancelled',
            'completed_at' => $campaign->completed_at ?? now(),
        ]);

        // AC-2.6.3: Bulk update all pending CampaignLog records to cancelled
        $oldSnapshots = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->get(['id', 'campaign_id', 'status', 'chat_id'])
            ->map(fn (CampaignLog $log): array => CampaignStatsService::snapshot($log))
            ->all();

        $cancelledCount = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $newSnapshots = collect($oldSnapshots)
            ->map(fn (array $row): array => array_merge($row, ['status' => 'cancelled']))
            ->all();

        CampaignStatsService::recordSnapshotsChanged($oldSnapshots, $newSnapshots);

        // AC-2.6.5: Calculate final stats
        $stats = Campaign::aggregateCountsForCampaignIds([$campaign->id])->get($campaign->id);

        return response()->json([
            'status' => 'cancelled',
            'message' => 'Campaign cancellation initiated',
            'stats' => [
                'sent' => (int) ($stats->total_sent_count ?? 0),
                'cancelled' => (int) $cancelledCount,
                'failed' => (int) ($stats->total_failed_count ?? 0),
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

        $rateLimitedQuery = $this->rateLimitedCampaignLogsQuery($campaign->id);

        $failedCount = $rateLimitedQuery->count();

        if ($failedCount === 0) {
            return response()->json(['error' => 'No rate-limited messages to resend'], 400);
        }

        $rateLimitedIds = (clone $rateLimitedQuery)->pluck('id');

        $rateLimitedQuery->update([
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        foreach ($rateLimitedIds as $rateLimitedId) {
            Cache::store('redis')->forget('campaign:dispatch:reserved:'.$rateLimitedId);
        }

        $campaign->update([
            'status' => 'ongoing',
            'started_at' => now(),
            'completed_at' => null,
        ]);

        ProcessCampaignMessagesJob::dispatchForOrganization($campaign->organization_id)
            ->onQueue(config('campaigns.queues.dispatchers', 'campaign-dispatchers'));

        return response()->json([
            'status' => 'ongoing',
            'message' => 'Resending rate-limited messages',
            'failed_count' => $failedCount,
        ]);
    }
}
