<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAutomationAiAvailable
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(config('ai.suspended', true), 503, 'Automation AI is temporarily suspended.');

        return $next($request);
    }
}
