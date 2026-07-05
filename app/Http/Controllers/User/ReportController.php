<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Services\ReportService;
use Inertia\Inertia;
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
        $contactsOverview = $service->contactsOverview($start, $end);
        $subs = $contactsOverview['subscription_counts'];
        // labels should represent distribution across all contacts (all time)
        $labels = $service->labelsStats(null, null);
        $areaSeries = $contactsOverview['area_series'];
        $new = $contactsOverview['new_contacts'];
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

        if (!$labelId || !is_numeric($labelId)) {
            return response()->json(['count' => 0]);
        }

        $labelId = (int) $labelId;

        $labelExists = DB::table('labels')
            ->where('id', $labelId)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$labelExists) {
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

        $q = DB::table('contact_label')
            ->where('label_id', $labelId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->whereExists(function ($exists) {
                $exists->selectRaw('1')
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'contact_label.contact_id')
                    ->whereNull('contacts.deleted_at');
            });

        $count = (int) $q->count();

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
