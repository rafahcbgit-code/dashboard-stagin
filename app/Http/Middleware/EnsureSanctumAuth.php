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
        $user = $request->user('sanctum');
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid or missing authentication token'
            ], 401);
        }
        
        //  Attach user to request for easy access in controllers
        $request->merge(['auth_user' => $user]);
        
        return $next($request);
    }
}