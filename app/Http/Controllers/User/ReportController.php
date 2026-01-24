<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Services\ReportService;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportController extends BaseController
{
    private function getCurrentOrganizationId()
    {
        return session()->get('current_organization');
    }

    public function index(Request $request)
    {
        $organizationId = $this->getCurrentOrganizationId();

        // Determine date range: use query params if provided, otherwise default to last 7 days
        if ($request->query('start_date')) {
            $start = Carbon::parse($request->query('start_date'))->startOfDay();
        } else {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }

        if ($request->query('end_date')) {
            $end = Carbon::parse($request->query('end_date'))->endOfDay();
        } else {
            $end = Carbon::now()->endOfDay();
        }

        $service = new ReportService($organizationId);

        // Total contacts should be reported as all-time (no date filter)
        $total = $service->totalContacts(null, null);
        $new = $service->newContactsCount($start, $end);
        $subs = $service->subscribedUnsubscribedCounts($start, $end);
        // labels should represent distribution across all contacts (all time)
        $labels = $service->labelsStats(null, null);
        $areaSeries = $service->dailyNewContactsSeries($start, $end);
        $conversations = $service->conversationsCounts($start, $end);
        $conversations_series = $service->dailyConversationsSeries($start, $end);
        $chats_series = $service->dailyChatsSeries($start, $end);
        // include unassigned across all time for quick visibility
        try {
            $conversations['unassigned_all_time'] = $service->unassignedAllTime();
        } catch (\Exception $e) {
            $conversations['unassigned_all_time'] = 0;
        }
        $messages = $service->messagesCount($start, $end);

        // Debug messages count
        Log::info('[Reports] messages_count', [
            'sent' => $messages['sent'] ?? 'missing',
            'received' => $messages['received'] ?? 'missing',
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
            'org_id' => $organizationId
        ]);

        // Debug closed tickets
        $closedTicketsSample = DB::table('chat_tickets')
            ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->where('chat_tickets.status', 'closed')
            ->whereBetween('chat_tickets.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select('chat_tickets.id', 'chat_tickets.status', 'chat_tickets.created_at')
            ->limit(10)
            ->get();
        Log::info('[Reports] closed_tickets_debug', [
            'count_in_range' => $closedTicketsSample->count(),
            'expected_count' => $conversations['closed'] ?? 0,
            'samples' => $closedTicketsSample->toArray()
        ]);

        try {
            Log::info('[Reports] conversations_debug', $conversations);
            $samples = DB::table('chat_tickets')
                ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
                ->where('contacts.organization_id', $organizationId)
                ->whereNull('contacts.deleted_at')
                ->where(function ($q) {
                    $q->whereNull('chat_tickets.assigned_to')->orWhere('chat_tickets.assigned_to', 0);
                })
                ->limit(10)
                ->get();
            Log::info('[Reports] unassigned_samples', ['rows' => $samples->toArray()]);
        } catch (\Exception $e) {
            Log::warning('[Reports] debug_failed', ['error' => $e->getMessage()]);
        }
        /*dd([
    'title' => __('General Reports'),
    'total_contacts' => $total,
    'new_contacts' => $new,
    'subscribed' => $subs['subscribed'],
    'unsubscribed' => $subs['unsubscribed'],
    'initial_start_date' => $start->toDateString(),
    'initial_end_date' => $end->toDateString(),
    'labels' => $labels,
             'conversations' => $conversations,
            'conversations_series' => $conversations_series,
       
]);*/



        // Debug logging: series sizes (remove in production)
        try {
            Log::info('[Reports] area_series_count', ['count' => is_array($areaSeries) ? count($areaSeries) : 0]);
            Log::info('[Reports] conversations_series', ['categories' => count($conversations_series['categories'] ?? []), 'data_count' => count($conversations_series['data'] ?? [])]);
        } catch (\Exception $e) {
            // ignore logging errors
        }

        return Inertia::render('User/Reports/General', [
            'title' => __('General Reports'),
            'total_contacts' => $total,
            'new_contacts' => $new,
            'subscribed' => $subs['subscribed'],
            'unsubscribed' => $subs['unsubscribed'],
            // Pass the initial date range so frontend inputs are prefilled
            'initial_start_date' => $start->toDateString(),
            'initial_end_date' => $end->toDateString(),
            'labels' => $labels,
            'labels_series' => $service->dailyLabelsSeries($start, $end),
            'area_series' => $areaSeries,
            'conversations' => $conversations,
            'conversations_series' => $conversations_series,
            'chats_series' => $chats_series,
            'messages' => $messages,
        ]);
    }

    public function countContactsByLabel(Request $request)
    {
        $organizationId = $this->getCurrentOrganizationId();
        $labelId = $request->query('label_id') ?? $request->input('label_id');
        $period = $request->query('period') ?? $request->input('period');

        if (!$labelId) {
            return response()->json(['count' => 0]);
        }

        $allowed = ['today', 'yesterday', 'last7'];
        if (!in_array($period, $allowed)) {
            $period = 'last7';
        }

        if ($period === 'today') {
            $start = Carbon::now()->startOfDay();
            $end = Carbon::now()->endOfDay();
        } elseif ($period === 'yesterday') {
            $start = Carbon::now()->subDay()->startOfDay();
            $end = Carbon::now()->subDay()->endOfDay();
        } else {
            // last7 days (including today)
            $start = Carbon::now()->startOfDay()->subDays(6);
            $end = Carbon::now()->endOfDay();
        }

        $q = \Illuminate\Support\Facades\DB::table('contact_label')
            ->join('contacts', 'contact_label.contact_id', '=', 'contacts.id')
            ->where('contact_label.label_id', $labelId)
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            // filter by when the label was attached (pivot created_at)
            ->whereBetween('contact_label.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        // log SQL and bindings for debugging
        try {
            \Illuminate\Support\Facades\Log::info('[Reports] countContactsByLabel - query', [
                'sql' => $q->toSql(),
                'bindings' => $q->getBindings(),
                'label_id' => $labelId,
                'org_id' => $organizationId,
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
                'period' => $period,
            ]);
        } catch (\Exception $e) {
            // ignore logging failures
        }

        $count = (int) $q->distinct()->count('contact_label.contact_id');

        \Illuminate\Support\Facades\Log::info('[Reports] countContactsByLabel - result', [
            'label_id' => $labelId,
            'org_id' => $organizationId,
            'period' => $period,
            'count' => $count,
        ]);

        return response()->json(['count' => $count]);
    }

    public function agentReports(Request $request)
    {
        $organizationId = $this->getCurrentOrganizationId();
        $businessHours = $request->query('business_hours') === 'true';

        if ($request->query('start_date')) {
            $start = Carbon::parse($request->query('start_date'))->startOfDay();
        } else {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }

        if ($request->query('end_date')) {
            $end = Carbon::parse($request->query('end_date'))->endOfDay();
        } else {
            $end = Carbon::now()->endOfDay();
        }

        $service = new ReportService($organizationId);
        $agentReports = $service->agentReports($start, $end, $businessHours);

        return Inertia::render('User/Reports/AgentReports', [
            'title' => __('Agent Reports'),
            'agent_reports' => $agentReports,
            'initial_start_date' => $start->toDateString(),
            'initial_end_date' => $end->toDateString(),
            'business_hours' => $businessHours,
        ]);
    }
}
