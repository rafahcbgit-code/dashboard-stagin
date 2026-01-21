<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Label;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Return statistics per label: id, title, color, count, percentage (relative to filtered total)
     * Filters by contact created_at range when provided.
     *
     * @return array<int,array>
     */
    public function labelsStats(?Carbon $start = null, ?Carbon $end = null): array
    {
        // total contacts in the same range
        $total = $this->totalContacts($start, $end);

        // build base contacts filter and pluck ids (safer across DB drivers)
        $contactsQuery = DB::table('contacts')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at');

        if ($start && $end) {
            $contactsQuery->whereBetween('contacts.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $contactsQuery->where('contacts.created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $contactsQuery->where('contacts.created_at', '<=', $end->toDateTimeString());
        }

        $contactIds = $contactsQuery->pluck('id')->toArray();

        $counts = [];
        if (!empty($contactIds)) {
            $rows = DB::table('contact_label')
                ->select('label_id', DB::raw('count(distinct contact_id) as count'))
                ->whereIn('contact_id', $contactIds)
                ->groupBy('label_id')
                ->get();

            $counts = $rows->keyBy('label_id')->map(function ($row) {
                return (int) $row->count;
            })->toArray();
        }

        // fetch labels for organization
        $labels = Label::where('organization_id', $this->organizationId)->whereNull('deleted_at')->get();

        $result = [];
        foreach ($labels as $label) {
            $cnt = isset($counts[$label->id]) ? $counts[$label->id] : 0;
            $pct = $total > 0 ? round(($cnt / $total) * 100, 2) : 0;
            $result[] = [
                'id' => $label->id,
                'uuid' => $label->uuid ?? null,
                'title' => $label->title,
                'color' => $label->color,
                'count' => (int) $cnt,
                'percentage' => $pct,
            ];
        }

        return $result;
    }

    /**
     * Conversations: total, open, closed counts within optional created_at range.
     * Uses chat_tickets joined to contacts to filter by organization.
     */
    public function conversationsCounts(?Carbon $start = null, ?Carbon $end = null): array
    {
        $base = DB::table('chat_tickets')
            ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at');

        if ($start && $end) {
            $base->whereBetween('chat_tickets.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $base->where('chat_tickets.created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $base->where('chat_tickets.created_at', '<=', $end->toDateTimeString());
        }

        $total = (int) (clone $base)->count();

        $open = (int) (clone $base)->where('chat_tickets.status', 'open')->count();
        $closed = (int) (clone $base)->where('chat_tickets.status', 'closed')->count();
        // treat assigned_to NULL or 0 as unassigned
        $unassigned = (int) (clone $base)
            ->where(function ($q) {
                $q->whereNull('chat_tickets.assigned_to')->orWhere('chat_tickets.assigned_to', 0);
            })->count();

        return [
            'total' => $total,
            'open' => $open,
            'closed' => $closed,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Daily conversations series (tickets created per day) as categories+data.
     */
    public function dailyConversationsSeries(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$start) {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }
        if (!$end) {
            $end = Carbon::now()->endOfDay();
        }

        $rows = DB::table('chat_tickets')
            ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at')
            ->whereBetween('chat_tickets.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select(DB::raw('DATE(chat_tickets.created_at) as day'), DB::raw('count(*) as count'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day')
            ->map(function ($r) {
                return (int) $r->count;
            })
            ->toArray();

        $days = [];
        $counts = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $dayStr = $cursor->toDateString();
            $days[] = $dayStr;
            $counts[] = isset($rows[$dayStr]) ? $rows[$dayStr] : 0;
            $cursor->addDay();
        }

        return ['categories' => $days, 'data' => $counts];
    }

    /**
     * Count unassigned conversations across all time for the organization.
     */
    public function unassignedAllTime(): int
    {
        $q = DB::table('chat_tickets')
            ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at')
            ->where(function ($q) {
                $q->whereNull('chat_tickets.assigned_to')->orWhere('chat_tickets.assigned_to', 0);
            });

        return (int) $q->count();
    }

    /**
     * Count sent and received messages within optional date range.
     * Returns ['sent' => count, 'received' => count]
     */
    public function messagesCount(?Carbon $start = null, ?Carbon $end = null): array
    {
        $query = DB::table('chats')
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $query->where('created_at', '<=', $end->toDateTimeString());
        }

        $sent = (int) (clone $query)->where('type', 'outbound')->count();
        $received = (int) (clone $query)->where('type', 'inbound')->count();

        return [
            'sent' => $sent,
            'received' => $received,
        ];
    }

    /**
     * Daily chats series split by type (inbound/outbound).
     * Returns ['categories' => [...days], 'inbound' => [...counts], 'outbound' => [...counts]]
     */
    public function dailyChatsSeries(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$start) {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }
        if (!$end) {
            $end = Carbon::now()->endOfDay();
        }

        $rows = DB::table('chats')
            ->select(DB::raw('DATE(created_at) as day'), 'type', DB::raw('count(*) as count'))
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy('day', 'type')
            ->orderBy('day')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $d = $r->day;
            $t = $r->type;
            if (!isset($map[$d])) $map[$d] = ['inbound' => 0, 'outbound' => 0];
            $map[$d][$t] = (int) $r->count;
        }

        $days = [];
        $inbound = [];
        $outbound = [];

        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $dayStr = $cursor->toDateString();
            $days[] = $dayStr;
            $inbound[] = isset($map[$dayStr]) ? $map[$dayStr]['inbound'] : 0;
            $outbound[] = isset($map[$dayStr]) ? $map[$dayStr]['outbound'] : 0;
            $cursor->addDay();
        }

        return ['categories' => $days, 'inbound' => $inbound, 'outbound' => $outbound];
    }

    /**
     * Return an array of { x: 'YYYY-MM-DD', y: count } points for each day in range.
     * Useful for datetime-area charts.
     *
     * @return array<int,array>
     */
    public function dailyNewContactsSeries(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$start) {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }
        if (!$end) {
            $end = Carbon::now()->endOfDay();
        }

        $rows = DB::table('contacts')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as count'))
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day')
            ->map(function ($r) {
                return (int) $r->count;
            })
            ->toArray();

        $points = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $dayStr = $cursor->toDateString();
            $points[] = [
                'x' => $dayStr,
                'y' => isset($rows[$dayStr]) ? $rows[$dayStr] : 0,
            ];
            $cursor->addDay();
        }

        return $points;
    }
}
