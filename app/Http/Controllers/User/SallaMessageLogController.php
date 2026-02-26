<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutboundMessageResource;
use App\Models\OutboundMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class SallaMessageLogController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $status = $request->query('status');
        $event = $request->query('event');
        $templateId = $request->query('template_id');

        $query = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'salla')
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

        // Date range filter for created_at
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

        return Inertia::render('User/Settings/SallaMessageLogs', [
            'title' => __('Salla Message Logs'),
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

    /**
     * Export filtered logs as CSV (Excel-friendly).
     */
    public function export(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $query = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'salla')
            ->orderByDesc('id');

        // Apply same filters as index
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

        $fileName = 'salla_message_logs_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        // Diagnostic counts: total rows for org, total salla rows, and filtered total
        $totalOrg = \App\Models\OutboundMessage::where('organization_id', $organizationId)->count();
        $totalSalla = \App\Models\OutboundMessage::where('organization_id', $organizationId)->where('source', 'salla')->count();
        $total = $query->count();

        // Sample up to 5 rows (for debugging) - list ids and created_at
        $sample = \App\Models\OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'salla')
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

        \Log::info('Salla message logs export', [
            'organization_id' => $organizationId,
            'totals' => [
                'org_total' => $totalOrg,
                'org_salla_total' => $totalSalla,
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

        // Log the SQL and bindings for the filtered query for debugging
        try {
            \Log::debug('Salla message logs export SQL', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        $callback = function () use ($query, $total) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Created At', 'Event', 'Automation', 'Recipient', 'Template', 'Status', 'Attempts', 'Error'
            ]);

            if ($total === 0) {
                // Write a placeholder row so the file isn't empty (helps debugging)
                fputcsv($handle, ['No records match the filters']);
            } else {
                // Stream rows
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

