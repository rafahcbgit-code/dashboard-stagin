<?php

namespace App\Http\Middleware;

use Closure;
use App\Helpers\SubscriptionHelper;
use App\Models\Team;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CheckClientRole
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
        if (Auth::check()) {
            $user = Auth::user();

            // Check if the user role is 'user'
            if ($user->role === 'user') {
                $organizationId = session()->get('current_organization');
                $teamRole = Cache::remember(
                    "team-role:{$organizationId}:{$user->id}",
                    now()->addMinutes(5),
                    fn () => Team::where('organization_id', $organizationId)
                        ->where('user_id', $user->id)
                        ->whereNull('deleted_at')
                        ->value('role')
                );

                if($teamRole === 'manager' || $teamRole === 'agent'){
                    return to_route('dashboard');
                }
            }
        }

        // Subscription is active or user role is not 'user', proceed to the next page
        return $next($request);
    }
}
