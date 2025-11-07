<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Label;
use Illuminate\Http\Request;

class LabelController extends Controller
{

    /* 
        All these are available after middleware
        $organizationId = $request->organization_id;
        $organizationUuid = $request->organization_uuid;
        $organizationName = $request->organization_name;
        $userRole = $request->user_role; // owner, manager, agent
        $teamStatus = $request->team_status; 
    
    */

    /**
     * Get all labels for the organization
     * GET /api/v1/labels
     * Header: X-Organization-UUID
     */
    public function index(Request $request)
    {
        try {
            // organization_id is already attached by CheckSelectedOrganization middleware
            $organizationId = $request->organization_id;

            // Get all labels for this organization
            $labels = Label::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->orderBy('title', 'asc')
                ->get()
                ->map(function ($label) {
                    return [
                        'id' => $label->id,
                        'title' => $label->title,
                        'color' => $label->color,
                        'created_at' => $label->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $labels,
                    'total' => $labels->count()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch labels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}