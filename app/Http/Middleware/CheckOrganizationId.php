<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use App\Helpers\SubscriptionHelper;
use Illuminate\Support\Facades\Auth;

class CheckOrganizationId
{
    public function handle($request, Closure $next)
    {
        if (!session()->has('current_organization')) {
            return redirect()->route('user.organization.index');
        }

        return $next($request);
    }
}
