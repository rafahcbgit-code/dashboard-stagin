<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionCancellationRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionCancellationRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $organizationId = (int) session()->get('current_organization');
        $userId = (int) $request->user()->id;

        $team = Team::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        abort_unless($team, 403, __('Only the organization owner can request subscription cancellation.'));

        $created = DB::transaction(function () use ($organizationId, $userId, $validated): bool {
            // Serialize requests per organization so simultaneous clicks cannot create duplicates.
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

            $pending = SubscriptionCancellationRequest::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                return false;
            }

            SubscriptionCancellationRequest::create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'reason' => trim($validated['reason']),
                'status' => 'pending',
            ]);

            return true;
        });

        return back()->with('status', [
            'type' => $created ? 'success' : 'info',
            'message' => $created
                ? __('Your cancellation request has been sent to the administrator. You will receive a response within 24 hours.')
                : __('A cancellation request for this organization is already under review.'),
        ]);
    }
}
