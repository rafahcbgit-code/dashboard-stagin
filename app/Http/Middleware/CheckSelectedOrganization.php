<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSelectedOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {   
        // Get authenticated user
        $user = $request->user('sanctum');
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Please login first'
            ], 401);
        }

        // Get organization_uuid from request header or body
        $organizationUuid = $request->header('X-Organization-UUID') 
                         ?? $request->input('organization_uuid');

        // Check if organization UUID is provided
        if (!$organizationUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Organization UUID is required. Please send X-Organization-UUID header or organization_uuid in request body.'
            ], 400);
        }

        // Find organization by UUID
        $organization = Organization::where('uuid', $organizationUuid)
            ->whereNull('deleted_at')
            ->first();

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid organization UUID'
            ], 404);
        }

        // Check if user belongs to this organization
        $team = Team::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$team) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this organization'
            ], 403);
        }

        // Check if team member status is active
        if ($team->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your access to this organization is inactive. Please contact your administrator.'
            ], 403);
        }

        // Attach organization data to request for easy access in controllers
        $request->merge([
            'organization_id' => $organization->id,
            'organization_uuid' => $organization->uuid,
            'organization_name' => $organization->name,
            'user_role' => $team->role, // owner, manager, agent
            'team_status' => $team->status
        ]);

        return $next($request);
    }
}