<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    /**
     * Get Pusher configuration for real-time messaging
     * GET /api/v1/config/pusher
     */
    public function pusher(Request $request)
    {
        try {
            $organizationId = $request->organization_id;

            // Get Pusher settings
            $pusherSettings = Setting::whereIn('key', [
                'pusher_app_id',
                'pusher_app_key',
                'pusher_app_secret',
                'pusher_app_cluster',
            ])->pluck('value', 'key')->toArray();

            // Check if Pusher is configured
            if (empty($pusherSettings['pusher_app_key']) || empty($pusherSettings['pusher_app_cluster'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pusher is not configured'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'pusher' => [
                        'app_key' => $pusherSettings['pusher_app_key'],
                        'cluster' => $pusherSettings['pusher_app_cluster'],
                        'channel' => 'chats.ch' . $organizationId, // Channel name
                        'event' => 'NewChatEvent', // Event name to listen to
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Pusher configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}