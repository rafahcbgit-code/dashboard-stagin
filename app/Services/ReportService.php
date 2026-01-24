<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Label;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class ReportService
{
    private $organizationId;

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
            ->whereNull('contacts.deleted_at')
            ->whereNotNull('contacts.created_at');

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

    /**
     * Return an array of agent performance metrics: agent name, open conversations, closed conversations, and average first response time.
     */
    public function agentReports(?Carbon $start = null, ?Carbon $end = null, bool $useBusinessHours = false): array
    {
        $agents = \App\Models\Team::where('organization_id', $this->organizationId)
            ->with('user')
            ->whereNull('deleted_at')
            ->get();

        $config = $useBusinessHours ? $this->getOrgConfig() : null;

        $data = [];
        foreach ($agents as $agent) {
            $userId = $agent->user_id;
            $userName = $agent->user ? ($agent->user->first_name . ' ' . $agent->user->last_name) : 'Unknown';

            $baseTicketQuery = DB::table('chat_tickets')
                ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
                ->where('contacts.organization_id', $this->organizationId)
                ->where('chat_tickets.assigned_to', $userId);

            if ($start && $end) {
                // Filter by ticket update/closure or creation? Usually reports are by the period the ticket was active or closed.
                // We'll use created_at for consistency with other reports.
                $baseTicketQuery->whereBetween('chat_tickets.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
            }

            $openCount = (clone $baseTicketQuery)->where('chat_tickets.status', 'open')->count();
            $closedCount = (clone $baseTicketQuery)->where('chat_tickets.status', 'closed')->count();

            // First Response Time & Waiting Time calculation
            $ticketsQuery = (clone $baseTicketQuery)->select('chat_tickets.id', 'chat_tickets.contact_id', 'chat_tickets.created_at');
            
            Log::info('[AgentReports] Tickets Query SQL', [
                'sql' => $ticketsQuery->toSql(),
                'bindings' => $ticketsQuery->getBindings()
            ]);

            $tickets = $ticketsQuery->get();
            
            $firstResponseTimes = [];
            $allWaitingTimes = [];

            foreach ($tickets as $ticket) {
                $msgQuery = DB::table('chats')
                    ->where('contact_id', $ticket->contact_id)
                    ->where('created_at', '>=', Carbon::parse($ticket->created_at)->subMinutes(5)->toDateTimeString())
                    ->orderBy('created_at', 'asc');

                $messages = $msgQuery->get();

                $firstInboundFound = false;
                $lastInboundTime = null;

                foreach ($messages as $msg) {
                    if ($msg->type === 'inbound') {
                        if (!$firstInboundFound) {
                            $lastInboundTime = $msg->created_at;
                            $firstInboundFound = true;
                        } else {
                            if ($lastInboundTime === null) {
                                $lastInboundTime = $msg->created_at;
                            }
                        }
                    } elseif ($msg->type === 'outbound' && $msg->user_id > 0) {
                        if ($lastInboundTime !== null) {
                            $diff = $this->calculateEffectiveSeconds($lastInboundTime, $msg->created_at, $config);
                            if ($diff >= 0) {
                                $allWaitingTimes[] = $diff;
                            }
                            $lastInboundTime = null; // Reset: we've replied to the current waiting block
                        }
                    }
                }

                // Dedicated First Response calculation for the ticket
                $firstInbound = $messages->where('type', 'inbound')->first();
                if ($firstInbound) {
                    $firstOutbound = $messages->where('type', 'outbound')->where('user_id', '>', 0)
                        ->where('created_at', '>', $firstInbound->created_at)
                        ->first();
                    if ($firstOutbound) {
                        $diff = $this->calculateEffectiveSeconds($firstInbound->created_at, $firstOutbound->created_at, $config);
                        if ($diff >= 0) {
                            $firstResponseTimes[] = $diff;
                        }
                    }
                }
            }

            $avgFirstResponseSeconds = count($firstResponseTimes) > 0 ? (array_sum($firstResponseTimes) / count($firstResponseTimes)) : 0;
            $avgWaitingSeconds = count($allWaitingTimes) > 0 ? (array_sum($allWaitingTimes) / count($allWaitingTimes)) : 0;

            Log::info("[AgentReports] Agent Final Metrics: $userName", [
                'avg_first_response' => $this->formatSeconds($avgFirstResponseSeconds),
                'avg_waiting_time' => $this->formatSeconds($avgWaitingSeconds),
                'tickets_calculated' => count($tickets),
                'waiting_blocks_found' => count($allWaitingTimes)
            ]);

            $data[] = [
                'agent_name' => $userName,
                'open_conversations_count' => $openCount,
                'closed_conversations_count' => $closedCount,
                'avg_first_response_time' => $this->formatSeconds($avgFirstResponseSeconds),
                'avg_customer_waiting_time' => $this->formatSeconds($avgWaitingSeconds),
            ];
        }

        return $data;
    }

    private function getOrgConfig()
    {
        $org = DB::table('organizations')->where('id', $this->organizationId)->first();
        if ($org && $org->metadata) {
            return json_decode($org->metadata, true);
        }
        return null;
    }

    private function calculateEffectiveSeconds($from, $to, $config)
    {
        if (!$config || !($config['enable_working_hours'] ?? false)) {
            return max(0, Carbon::parse($to)->diffInSeconds(Carbon::parse($from)));
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        
        if ($start->gt($end)) return 0;

        $workingHours = [];
        foreach ($config['working_hours'] ?? [] as $wh) {
            $workingHours[$wh['day']] = $wh;
        }

        $totalSeconds = 0;
        $current = $start->copy();

        while ($current->toDateString() <= $end->toDateString()) {
            $dayName = $current->format('l');
            $wh = $workingHours[$dayName] ?? null;

            if ($wh && ($wh['active'] ?? false)) {
                $dayStart = $current->copy()->startOfDay();
                $dayEnd = $current->copy()->endOfDay();

                // Configured working hours for this day
                $workStart = $dayStart->copy()->setTimeFromTimeString($wh['from']);
                $workEnd = $dayStart->copy()->setTimeFromTimeString($wh['to']);

                // The period we are measuring (on this specific day)
                $actualStart = $start->gt($dayStart) ? $start : $dayStart;
                $actualEnd = $end->lt($dayEnd) ? $end : $dayEnd;

                // Overlap between [workStart, workEnd] and [actualStart, actualEnd]
                $overlapStart = $workStart->gt($actualStart) ? $workStart : $actualStart;
                $overlapEnd = $workEnd->lt($actualEnd) ? $workEnd : $actualEnd;

                if ($overlapStart->lt($overlapEnd)) {
                    $totalSeconds += $overlapStart->diffInSeconds($overlapEnd);
                }
            }
            $current->addDay()->startOfDay();
        }

        return $totalSeconds;
    }

    private function formatSeconds($seconds)
    {
        if ($seconds <= 0) return '-';
        
        if ($seconds < 60) {
            return round($seconds) . 's';
        }
        
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = round(($seconds % 3600) / 60);
        
        if ($minutes == 0) return $hours . 'h';
        return $hours . 'h ' . $minutes . 'm';
    }
}
