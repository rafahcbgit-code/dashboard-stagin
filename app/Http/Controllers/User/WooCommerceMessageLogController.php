<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutboundMessageResource;
use App\Models\OutboundMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WooCommerceMessageLogController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $status = $request->query('status');
        $event = $request->query('event');
        $templateId = $request->query('template_id');

        $query = OutboundMessage::where('organization_id', $organizationId)
            ->where('source', 'woocommerce')
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
            }
        }

        if (is_string($createdTo) && $createdTo !== '') {
            try {
                $to = Carbon::parse($createdTo)->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (\Throwable) {
            }
        }

        $rows = $query->paginate(25)->withQueryString();

        if ($request->wantsJson()) {
            return OutboundMessageResource::collection($rows);
        }

        return Inertia::render('User/Settings/WooCommerceMessageLogs', [
            'title' => __('WooCommerce Message Logs'),
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
            ->where('source', 'woocommerce')
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

        try {
            if ($request->filled('created_from')) {
                $query->where('created_at', '>=', Carbon::parse($request->query('created_from'))->startOfDay());
            }
        } catch (\Throwable) {
        }

        try {
            if ($request->filled('created_to')) {
                $query->where('created_at', '<=', Carbon::parse($request->query('created_to'))->endOfDay());
            }
        } catch (\Throwable) {
        }

        $fileName = 'woocommerce_message_logs_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Created At', 'Event', 'Automation', 'Recipient', 'Template', 'Status', 'Attempts', 'Error',
            ]);

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

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}

