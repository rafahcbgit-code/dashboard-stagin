<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Services\ReportService;
use Inertia\Inertia;
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
            $start = Carbon::parse($request->query('start_date'));
        } else {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }

        if ($request->query('end_date')) {
            $end = Carbon::parse($request->query('end_date'));
        } else {
            $end = Carbon::now()->endOfDay();
        }

        $service = new ReportService($organizationId);

        $total = $service->totalContacts($start, $end);
        $new = $service->newContactsCount($start, $end);
        $subs = $service->subscribedUnsubscribedCounts($start, $end);
        $labels = $service->labelsStats($start, $end);
        $areaSeries = $service->dailyNewContactsSeries($start, $end);
/*dd([
    'title' => __('General Reports'),
    'total_contacts' => $total,
    'new_contacts' => $new,
    'subscribed' => $subs['subscribed'],
    'unsubscribed' => $subs['unsubscribed'],
    'initial_start_date' => $start->toDateString(),
    'initial_end_date' => $end->toDateString(),
    'labels' => $labels,
]);*/



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
        ]);
    }
}
