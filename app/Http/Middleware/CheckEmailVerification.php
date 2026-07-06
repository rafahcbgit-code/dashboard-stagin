<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CheckEmailVerification
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
        if (Auth::check()) {
            $user = Auth::user();
            $verifyEmail = Cache::remember(
                'settings:verify_email',
                now()->addMinutes(10),
                fn () => Setting::where('key', 'verify_email')->value('value')
            );

            // Check if the user role is 'user'
            if ($verifyEmail == '1' && $user->email_verified_at === NULL) {
                return to_route('verification.notice');
            }
        }

        // Subscription is active or user role is not 'user', proceed to the next page
        return $next($request);
    }
}
