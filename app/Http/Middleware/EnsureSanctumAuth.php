<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated via Sanctum
        if (!$request->user('sanctum')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid or missing authentication token'
            ], 401);
        }

        return $next($request);
    }
}