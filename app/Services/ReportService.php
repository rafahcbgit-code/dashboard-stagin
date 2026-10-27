<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Label;
use App\Support\ContactPageCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private $organizationId;
    private array $conversationStartCache = [];
    private array $contactsCountCache = [];

    public function __construct(int $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    protected function baseQuery(?Carbon $start = null, ?Carbon $end = null)
    {
        $query = Contact::where('organization_id', $this->organizationId)->whereNull('deleted_at')->whereNotNull('created_at');

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
        if (!$start && !$end) {
            return Cache::remember(
                ContactPageCache::contactsCountKey($this->organizationId),
                now()->addMinutes(30),
                fn () => (int) $this->baseQuery(null, null)->count()
            );
        }

        $cacheKey = $this->contactsCountCacheKey($start, $end);

        if (!array_key_exists($cacheKey, $this->contactsCountCache)) {
            $this->contactsCountCache[$cacheKey] = (int) $this->baseQuery($start, $end)->count();
        }

        return $this->contactsCountCache[$cacheKey];
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
        $row = $this->baseQuery($start, $end)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_subscribed = 1 THEN 1 ELSE 0 END) as subscribed')
            ->selectRaw('SUM(CASE WHEN is_subscribed = 0 THEN 1 ELSE 0 END) as unsubscribed')
            ->first();

        $total = (int) ($row->total ?? 0);
        $subscribed = (int) ($row->subscribed ?? 0);
        $unsubscribed = (int) ($row->unsubscribed ?? 0);

        $this->contactsCountCache[$this->contactsCountCacheKey($start, $end)] = $total;

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

    public function contactsOverview(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$start) {
            $start = Carbon::now()->startOfDay()->subDays(6);
        }
        if (!$end) {
            $end = Carbon::now()->endOfDay();
        }

        $rows = DB::table('contacts')
            ->select(DB::raw('DATE(created_at) as day'))
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(CASE WHEN is_subscribed = 1 THEN 1 ELSE 0 END) as subscribed')
            ->selectRaw('SUM(CASE WHEN is_subscribed = 0 THEN 1 ELSE 0 END) as unsubscribed')
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $points = [];
        $total = 0;
        $subscribed = 0;
        $unsubscribed = 0;
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $dayStr = $cursor->toDateString();
            $row = $rows->get($dayStr);
            $count = (int) ($row->count ?? 0);

            $total += $count;
            $subscribed += (int) ($row->subscribed ?? 0);
            $unsubscribed += (int) ($row->unsubscribed ?? 0);

            $points[] = [
                'x' => $dayStr,
                'y' => $count,
            ];

            $cursor->addDay();
        }

        $this->contactsCountCache[$this->contactsCountCacheKey($start, $end)] = $total;

        return [
            'new_contacts' => $total,
            'area_series' => $points,
            'subscription_counts' => [
                'total' => $total,
                'subscribed' => [
                    'count' => $subscribed,
                    'percentage' => $total > 0 ? round(($subscribed / $total) * 100, 2) : 0,
                ],
                'unsubscribed' => [
                    'count' => $unsubscribed,
                    'percentage' => $total > 0 ? round(($unsubscribed / $total) * 100, 2) : 0,
                ],
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

        $labels = DB::table('labels')
            ->leftJoin('contact_label', 'contact_label.label_id', '=', 'labels.id')
            ->leftJoin('contacts', function ($join) use ($start, $end) {
                $join->on('contact_label.contact_id', '=', 'contacts.id')
                    ->where('contacts.organization_id', $this->organizationId)
                    ->whereNull('contacts.deleted_at')
                    ->whereNotNull('contacts.created_at');

                if ($start && $end) {
                    $join->whereBetween('contacts.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
                } elseif ($start) {
                    $join->where('contacts.created_at', '>=', $start->toDateTimeString());
                } elseif ($end) {
                    $join->where('contacts.created_at', '<=', $end->toDateTimeString());
                }
            })
            ->where('labels.organization_id', $this->organizationId)
            ->whereNull('labels.deleted_at')
            ->select(
                'labels.id',
                'labels.title',
                'labels.color',
                DB::raw('count(contacts.id) as count')
            )
            ->groupBy('labels.id', 'labels.title', 'labels.color')
            ->orderBy('labels.title')
            ->get()
            ->map(function ($label) {
                $label->count = (int) $label->count;

                return $label;
            });

        $result = [];
        foreach ($labels as $label) {
            $cnt = (int) $label->count;
            $pct = $total > 0 ? round(($cnt / $total) * 100, 2) : 0;
            $result[] = [
                'id' => $label->id,
                'uuid' => null,
                'title' => $label->title,
                'color' => $label->color,
                'count' => $cnt,
                'percentage' => $pct,
            ];
        }

        return $result;
    }

    /**
     * Daily label assignment series (when labels were attached to contacts)
     */
    public function dailyLabelsSeries(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$start) $start = Carbon::now()->startOfDay()->subDays(6);
        if (!$end) $end = Carbon::now()->endOfDay();

        $rows = DB::table('contact_label')
            ->join('contacts', 'contact_label.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at')
            ->whereBetween('contact_label.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select(
                DB::raw('DATE(contact_label.created_at) as day'),
                'contact_label.label_id',
                DB::raw('count(*) as count')
            )
            ->groupBy('day', 'label_id')
            ->get();

        $labels = Label::where('organization_id', $this->organizationId)->whereNull('deleted_at')->pluck('title', 'id');

        $map = []; 
        $days = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $dayStr = $cursor->toDateString();
            $days[] = $dayStr;
            $cursor->addDay();
        }

        foreach ($rows as $row) {
            $map[$row->label_id][$row->day] = (int) $row->count;
        }

        $series = [];
        foreach ($labels as $id => $title) {
            $data = [];
            foreach ($days as $day) {
                $data[] = $map[$id][$day] ?? 0;
            }
            if (array_sum($data) > 0) {
                $series[] = [
                    'name' => $title,
                    'data' => $data
                ];
            }
        }

        return ['categories' => $days, 'series' => $series];
    }

    /**
     * Conversations: total, open, closed counts within optional created_at range.
     */
    public function conversationsCounts(?Carbon $start = null, ?Carbon $end = null): array
    {
        $base = DB::table('chat_tickets')
            ->where('organization_id', $this->organizationId);

        if ($start && $end) {
            $base->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } elseif ($start) {
            $base->where('created_at', '>=', $start->toDateTimeString());
        } elseif ($end) {
            $base->where('created_at', '<=', $end->toDateTimeString());
        }

        $row = $base
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open")
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed")
            ->selectRaw('SUM(CASE WHEN assigned_to IS NULL OR assigned_to = 0 THEN 1 ELSE 0 END) as unassigned')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'open' => (int) ($row->open ?? 0),
            'closed' => (int) ($row->closed ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
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
            ->where('organization_id', $this->organizationId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as count'))
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
            ->where('organization_id', $this->organizationId)
            ->where(function ($q) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', 0);
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

        $row = $query
            ->selectRaw("SUM(CASE WHEN type = 'outbound' THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN type = 'inbound' THEN 1 ELSE 0 END) as received")
            ->first();

        return [
            'sent' => (int) ($row->sent ?? 0),
            'received' => (int) ($row->received ?? 0),
        ];
    }

    private function contactsCountCacheKey(?Carbon $start = null, ?Carbon $end = null): string
    {
        return ($start?->toDateTimeString() ?? 'null') . '|' . ($end?->toDateTimeString() ?? 'null');
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

    /**
     * Return an array of agent performance metrics: agent name, open conversations, closed conversations, and average first response time.
     */
    public function agentReports(?Carbon $start = null, ?Carbon $end = null, bool $useBusinessHours = false): array
    {
        $agents = \App\Models\Team::where('organization_id', $this->organizationId)
            ->with('user')
            ->whereNull('deleted_at')
            ->get();

        $schedule = $useBusinessHours ? app(WorkingHoursService::class)->getSchedule($this->organizationId) : null;
        $config = $schedule ? ['enable_working_hours' => true, 'timezone' => $schedule['timezone'], 'working_hours' => $schedule['days']] : null;
        $metrics = [];
        $tickets = DB::table('chat_tickets')->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->select('chat_tickets.*')->orderBy('chat_tickets.id', 'desc')->get()->unique('contact_id');

        foreach ($tickets->chunk(200) as $batch) {
            $ids = $batch->pluck('contact_id')->all();
            $messagesByContact = DB::table('chats')->whereIn('contact_id', $ids)
                ->where('organization_id', $this->organizationId)
                ->when($end, fn ($query) => $query->where('created_at', '<=', $end->copy()->setTimezone(config('app.timezone', 'UTC'))->toDateTimeString()))
                ->orderBy('created_at')->orderBy('id')->get()->groupBy('contact_id');
            $eventsByContact = DB::table('chat_ticket_logs')->whereIn('contact_id', $ids)
                ->whereIn('description', ['Conversation was moved from closed to open', 'Conversation was moved from opened to closed'])
                ->when($end, fn ($query) => $query->where('created_at', '<=', $end->copy()->setTimezone(config('app.timezone', 'UTC'))->toDateTimeString()))
                ->orderBy('created_at')->orderBy('id')->get()->groupBy('contact_id');
            $orderByContact = DB::table('chat_logs')->whereIn('contact_id', $ids)
                ->whereIn('entity_type', ['chat', 'ticket'])->orderBy('id')->get(['id', 'contact_id', 'entity_type', 'entity_id'])->groupBy('contact_id');
        foreach ($batch as $ticket) {
            $messages = $messagesByContact->get($ticket->contact_id, collect());
            $events = $eventsByContact->get($ticket->contact_id, collect());
            $sequence = [];
            foreach ($orderByContact->get($ticket->contact_id, collect()) as $log) {
                $sequence[$log->entity_type . ':' . $log->entity_id] ??= $log->id;
            }
            $timeline = [];
            foreach ($messages as $message) $timeline[] = ['time' => $message->created_at, 'kind' => 'message', 'id' => $message->id, 'sequence' => $sequence['chat:' . $message->id] ?? null, 'value' => $message];
            foreach ($events as $event) $timeline[] = ['time' => $event->created_at, 'kind' => 'event', 'id' => $event->id, 'sequence' => $sequence['ticket:' . $event->id] ?? null, 'value' => $event];
            usort($timeline, function ($a, $b) {
                $time = strcmp($a['time'], $b['time']);
                if ($time) return $time;
                // Existing unified chat logs preserve ordering across tables at second precision.
                if ($a['sequence'] !== $b['sequence']) return ($a['sequence'] ?? PHP_INT_MAX) <=> ($b['sequence'] ?? PHP_INT_MAX);
                return $a['kind'] === $b['kind'] ? $a['id'] <=> $b['id'] : ($a['kind'] === 'event' ? -1 : 1);
            });
            $waitingSince = null;
            $firstResponseDone = false;
            foreach ($timeline as $item) {
                if ($item['kind'] === 'event') {
                    if (str_contains($item['value']->description, 'closed to open')) {
                        $firstResponseDone = false;
                    } else {
                        $waitingSince = null;
                    }
                    continue;
                }
                $message = $item['value'];
                if ($message->type === 'inbound') {
                    if ($waitingSince === null) $waitingSince = $message->created_at;
                } elseif ($message->type === 'outbound' && $message->user_id > 0 && $message->status !== 'failed' && $waitingSince !== null) {
                    $responseAt = Carbon::parse($message->created_at, config('app.timezone', 'UTC'));
                    if ((!$start || $responseAt->gte($start)) && (!$end || $responseAt->lte($end))) {
                        $seconds = $this->calculateEffectiveSeconds($waitingSince, $message->created_at, $config);
                        $metrics[$message->user_id]['waiting'][] = $seconds;
                        if (!$firstResponseDone) $metrics[$message->user_id]['first'][] = $seconds;
                    }
                    $firstResponseDone = true;
                    $waitingSince = null;
                }
            }
        }
        }

        $data = [];
        foreach ($agents as $agent) {
            $userId = $agent->user_id;
            $userName = $agent->user ? ($agent->user->first_name . ' ' . $agent->user->last_name) : 'Unknown';
            $assigned = $tickets->where('assigned_to', $userId);
            $first = $metrics[$userId]['first'] ?? [];
            $waiting = $metrics[$userId]['waiting'] ?? [];
            $data[] = [
                'agent_name' => $userName,
                'open_conversations_count' => $assigned->where('status', 'open')->count(),
                'closed_conversations_count' => $assigned->where('status', 'closed')->count(),
                'avg_first_response_time' => $this->formatSeconds(count($first) ? array_sum($first) / count($first) : null),
                'avg_customer_waiting_time' => $this->formatSeconds(count($waiting) ? array_sum($waiting) / count($waiting) : null),
            ];
        }

        return $data;
    }

    private function calculateEffectiveSeconds($from, $to, $config)
    {
        if (!$config || !($config['enable_working_hours'] ?? false)) {
            return max(0, Carbon::parse($to)->diffInSeconds(Carbon::parse($from)));
        }

        $timezone = $config['timezone'] ?? config('app.timezone', 'UTC');
        $start = Carbon::parse($from, config('app.timezone', 'UTC'))->setTimezone($timezone);
        $end = Carbon::parse($to, config('app.timezone', 'UTC'))->setTimezone($timezone);
        
        if ($start->gt($end)) return 0;

        $workingHours = [];
        foreach ($config['working_hours'] ?? [] as $wh) {
            $workingHours[$wh['day']] = $wh;
        }

        $intervals = [];
        $current = $start->copy()->subDay()->startOfDay();

        while ($current->toDateString() <= $end->toDateString()) {
            $dayName = $current->format('l');
            $wh = $workingHours[$dayName] ?? null;

            if ($wh && ($wh['active'] ?? false) && !empty($wh['from']) && !empty($wh['to'])) {
                $dayStart = $current->copy()->startOfDay();

                // Configured working hours for this day
                $workStart = $dayStart->copy()->setTimeFromTimeString($wh['from']);
                $workEnd = $dayStart->copy()->setTimeFromTimeString($wh['to']);
                if ($workEnd->lt($workStart)) $workEnd->addDay();
                // The configured ending minute is inclusive, as in WorkingHoursService.
                if (strlen($wh['to']) === 5) $workEnd->addMinute();

                // The period we are measuring (on this specific day)
                $actualStart = $start;
                $actualEnd = $end;

                // Overlap between [workStart, workEnd] and [actualStart, actualEnd]
                $overlapStart = $workStart->gt($actualStart) ? $workStart : $actualStart;
                $overlapEnd = $workEnd->lt($actualEnd) ? $workEnd : $actualEnd;

                if ($overlapStart->lt($overlapEnd)) {
                    $intervals[] = [$overlapStart->copy(), $overlapEnd->copy()];
                }
            }
            $current->addDay()->startOfDay();
        }

        // Consecutive configured shifts can overlap; count each instant once.
        usort($intervals, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        $totalSeconds = 0;
        $lastStart = null;
        $lastEnd = null;
        foreach ($intervals as [$intervalStart, $intervalEnd]) {
            if ($lastEnd && $intervalStart->lte($lastEnd)) {
                if ($intervalEnd->gt($lastEnd)) $lastEnd = $intervalEnd;
            } else {
                if ($lastEnd) $totalSeconds += $lastStart->diffInSeconds($lastEnd);
                $lastStart = $intervalStart;
                $lastEnd = $intervalEnd;
            }
        }
        if ($lastEnd) $totalSeconds += $lastStart->diffInSeconds($lastEnd);
        return $totalSeconds;
    }

    private function formatSeconds($seconds)
    {
        if ($seconds === null) return '-';
        if ($seconds <= 0) return '0s';
        
        if ($seconds < 60) {
            return round($seconds) . 's';
        }
        
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        
        $totalMinutes = (int) round($seconds / 60);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;
        
        if ($minutes == 0) return $hours . 'h';
        return $hours . 'h ' . $minutes . 'm';
    }
}
