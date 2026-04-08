<?php

namespace App\Http\Controllers\Zid;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\ZidWebhookSubscription;
use App\Services\Zid\ZidWebhookSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ZidSubscriptionController extends Controller
{
    public function update(Request $request, ZidWebhookSubscriptionService $service)
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $organizationId = session()->get('current_organization');
        $connection = IntegrationConnection::where('organization_id', $organizationId)
            ->where('platform', config('zid.platform'))
            ->first();

        if (! $connection) {
            return back()->with('error', __('Zid integration is not connected.'));
        }

        $event = $data['event'];
        
        $enabled = $data['enabled'];
        $subscription = ZidWebhookSubscription::where('store_id', $connection->store_id)
            ->where('event_name', $event)
            ->first();

        try {
            if ($enabled) {
                if ($subscription && $subscription->status === 'active') {
                    return back()->with('success', __('Subscription already active.'));
                }

                $service->subscribeEvent($connection, $event);
            } else {
                if ($subscription) {
                    $service->unsubscribeEvent($subscription);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to toggle Zid subscription', [
                'event' => $event,
                'enabled' => $enabled,
                'exception' => $exception->getMessage(),
            ]);

            return back()->with('error', __('Failed to update Zid subscription.'));
        }

        return back()->with('success', __('Subscription updated successfully.'));
    }
}
