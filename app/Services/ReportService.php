<?php

namespace App\Services;

use App\Models\Contact;
use Carbon\Carbon;

class ReportService
{
    private $organizationId;

    public function __construct(int $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    protected function baseQuery(?Carbon $start = null, ?Carbon $end = null)
    {
        $query = Contact::where('organization_id', $this->organizationId)->whereNull('deleted_at');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $query->where('created_at', '<=', $end->toDateTimeString());
        }

        return $query;
    }

    public function totalContacts(?Carbon $start = null, ?Carbon $end = null): int
    {
        return $this->baseQuery($start, $end)->count();
    }

    public function newContactsCount(?Carbon $start = null, ?Carbon $end = null): int
    {
        $query = Contact::where('organization_id', $this->organizationId)->whereNull('deleted_at');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $query->where('created_at', '<=', $end->toDateTimeString());
        } else {
            $startOfDay = Carbon::now()->startOfDay();
            $query->whereBetween('created_at', [$startOfDay->toDateTimeString(), Carbon::now()->toDateTimeString()]);
        }

        return $query->count();
    }

    public function subscribedUnsubscribedCounts(?Carbon $start = null, ?Carbon $end = null): array
    {
        $total = $this->totalContacts($start, $end);

        $subscribed = $this->baseQuery($start, $end)->where('is_subscribed', 1)->count();
        $unsubscribed = $this->baseQuery($start, $end)->where('is_subscribed', 0)->count();

        $subscribedPercent = $total > 0 ? round(($subscribed / $total) * 100, 2) : 0;
        $unsubscribedPercent = $total > 0 ? round(($unsubscribed / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'subscribed' => [
                'count' => $subscribed,
                'percentage' => $subscribedPercent,
            ],
            'unsubscribed' => [
                'count' => $unsubscribed,
                'percentage' => $unsubscribedPercent,
            ],
        ];
    }
}
