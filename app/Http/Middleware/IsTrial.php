<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Subscription;
use App\Models\OtpConfig;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;

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
        $subscription = Cache::remember(
            "subscription:trial-middleware:{$organizationId}",
            now()->addMinute(),
            fn () => Subscription::where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->first(['id', 'organization_id', 'status', 'valid_until'])
        );

        $otpConfig = Cache::remember(
            'otp_config:contact_links',
            now()->addMinutes(10),
            fn () => OtpConfig::query()
                ->orderBy('id')
                ->first(['contact_phone', 'calendly_url'])
        );
        $phone = $otpConfig?->contact_phone ?? "+966542922948";
        $calendly_url = $otpConfig?->calendly_url ?? "https://calendly.com/chatberry-info/chatberry-whatsapp-meeting-clone?month=2025-07";

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
