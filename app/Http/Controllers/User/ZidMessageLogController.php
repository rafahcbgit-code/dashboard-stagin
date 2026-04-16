<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutboundMessageResource;
use App\Models\OutboundMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class ZidMessageLogController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $status = $request->query('status');
        $event = $request->query('event');
        $templateId = $request->query('template_id');

        $query = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'zid')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('event')) {
            $query->where('source_event', $request->query('event'));
        }

        if ($request->filled('template_id') && is_numeric($templateId)) {
            $query->where('template_id', (int) $templateId);
        }

        $createdFrom = $request->query('created_from');
        $createdTo = $request->query('created_to');

        if (is_string($createdFrom) && $createdFrom !== '') {
            try {
                $from = Carbon::parse($createdFrom)->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (\Throwable) {
                // ignore parse errors
            }
        }

        if (is_string($createdTo) && $createdTo !== '') {
            try {
                $to = Carbon::parse($createdTo)->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (\Throwable) {
                // ignore parse errors
            }
        }

        $rows = $query->paginate(25)->withQueryString();

        if ($request->wantsJson()) {
            return OutboundMessageResource::collection($rows);
        }

        return Inertia::render('User/Settings/ZidMessageLogs', [
            'title' => __('Zid Message Logs'),
            'rows' => OutboundMessageResource::collection($rows),
            'filters' => [
                'status' => $status,
                'event' => $event,
                'template_id' => $templateId,
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $query = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'zid')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('event')) {
            $query->where('source_event', $request->query('event'));
        }

        if ($request->filled('template_id') && is_numeric($request->query('template_id'))) {
            $query->where('template_id', (int) $request->query('template_id'));
        }

        $createdFrom = $request->query('created_from');
        $createdTo = $request->query('created_to');
        try {
            if (is_string($createdFrom) && $createdFrom !== '') {
                $from = Carbon::parse($createdFrom)->startOfDay();
                $query->where('created_at', '>=', $from);
            }
        } catch (\Throwable) {}

        try {
            if (is_string($createdTo) && $createdTo !== '') {
                $to = Carbon::parse($createdTo)->endOfDay();
                $query->where('created_at', '<=', $to);
            }
        } catch (\Throwable) {}

        $fileName = 'zid_message_logs_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        $totalOrg = OutboundMessage::where('organization_id', $organizationId)->count();
        $totalZid = OutboundMessage::where('organization_id', $organizationId)->where('source', 'zid')->count();
        $total = $query->count();

        $sample = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'zid')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'created_at' => optional($r->created_at)->toDateTimeString(),
                    'source_event' => $r->source_event,
                ];
            });

        \Log::info('Zid message logs export', [
            'organization_id' => $organizationId,
            'totals' => [
                'org_total' => $totalOrg,
                'org_zid_total' => $totalZid,
            ],
            'filters' => [
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'status' => $request->query('status'),
                'event' => $request->query('event'),
                'template_id' => $request->query('template_id'),
            ],
            'result_count' => $total,
            'sample' => $sample,
        ]);

        try {
            \Log::debug('Zid message logs export SQL', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        } catch (\Throwable) {}

        $callback = function () use ($query, $total) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Created At', 'Event', 'Automation', 'Recipient', 'Template', 'Status', 'Attempts', 'Error'
            ]);

            if ($total === 0) {
                fputcsv($handle, ['No records match the filters']);
            } else {
                foreach ($query->cursor() as $row) {
                    fputcsv($handle, [
                        optional($row->created_at)->toDateTimeString(),
                        $row->source_event,
                        $row->automation_key,
                        $row->recipient_e164,
                        $row->template_name ?? $row->template_id,
                        $row->status,
                        $row->attempts,
                        $row->last_error_message,
                    ]);
                }
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
