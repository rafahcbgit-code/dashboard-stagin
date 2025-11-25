<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Subscription;

class IsTrial
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
       $organizationId = session()->get('current_organization');

        if (!$organizationId) {
            return response()->json([
                'message' => 'Organization not found.'
            ], 403);
        }

        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if ($subscription && $subscription->status === 'trial') {
            $request->merge(['is_trial' => 1]);
        }

        return $next($request);
    }
}
