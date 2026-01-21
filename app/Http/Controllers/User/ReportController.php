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

        $total = $service->totalContacts($start, $end);
        $new = $service->newContactsCount($start, $end);
        $subs = $service->subscribedUnsubscribedCounts($start, $end);
        $labels = $service->labelsStats($start, $end);
        $areaSeries = $service->dailyNewContactsSeries($start, $end);
        $conversations = $service->conversationsCounts($start, $end);
        $conversations_series = $service->dailyConversationsSeries($start, $end);
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
            'area_series' => $areaSeries,
            'conversations' => $conversations,
            'conversations_series' => $conversations_series,
            'messages' => $messages,
        ]);
    }
}
