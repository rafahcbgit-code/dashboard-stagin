<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Subscription;
use App\Models\OtpConfig;
use Inertia\Inertia;

class IsTrial
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = session()->get('current_organization');

        if (!$organizationId) {
            return response()->json(['message' => 'Organization not found.'], 403);
        }

        $user = auth()->user();
        if ($user->code_verified != 1) {
            return Inertia::location(route('VerifyAccount'));
        }
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        $phone = OtpConfig::value('contact_phone') ?? "+966542922948";
        $calendly_url = OtpConfig::value('calendly_url') ?? "https://calendly.com/chatberry-info/chatberry-whatsapp-meeting-clone?month=2025-07";

        $phone = ltrim($phone, '+');

        $request->merge([
            'contact_phone' => $phone,
            'whatsapp_link' => "https://wa.me/{$phone}",
            'calendly_url' => $calendly_url
        ]);

        if ($subscription) {
            if ($subscription->status === 'suspended') {
                return to_route('user.account.suspended');
            }

            if ($subscription->status === 'trial') {
                $request->merge(['is_trial' => 1]);
            }

            return $next($request);
        }

        return Inertia::location(route('VerifyAccount'));
    }
}
