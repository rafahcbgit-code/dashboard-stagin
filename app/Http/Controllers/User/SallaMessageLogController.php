<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutboundMessageResource;
use App\Models\OutboundMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;

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

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($event) && $event !== '') {
            $query->where('source_event', $event);
        }

        if (is_numeric($templateId)) {
            $query->where('template_id', (int) $templateId);
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
            ],
        ]);
    }
}

