<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionCancellationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionCancellationRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $requests = SubscriptionCancellationRequest::query()
            ->with([
                'organization:id,name,uuid',
                'user:id,first_name,last_name,email',
                'reviewer:id,first_name,last_name',
            ])
            ->when(in_array($status, ['pending', 'reviewed'], true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/SubscriptionCancellationRequests/Index', [
            'title' => __('Subscription cancellation requests'),
            'rows' => $requests,
            'filters' => ['status' => $status],
        ]);
    }

    public function markReviewed(Request $request, SubscriptionCancellationRequest $cancellationRequest): RedirectResponse
    {
        $validated = $request->validate([
            'admin_response' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        if ($cancellationRequest->status === 'pending') {
            $cancellationRequest->update([
                'status' => 'reviewed',
                'admin_response' => trim($validated['admin_response']),
                'reviewed_by' => auth('admin')->id(),
                'reviewed_at' => now(),
                'responded_at' => now(),
            ]);
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Cancellation request marked as reviewed.'),
        ]);
    }
}
