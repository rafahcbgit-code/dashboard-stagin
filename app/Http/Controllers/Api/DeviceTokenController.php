<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceTokenController extends Controller
{
    private $firebaseService;

    public function __construct()
    {
        $this->firebaseService = new FirebaseService();
    }

    /**
     * Register device token
     * POST /api/v1/device-token/register
     * 
     * Body:
     * {
     *     "device_token": "fcm_token_here",
     *     "device_type": "android", // or "ios"
     *     "device_name": "Samsung Galaxy S21",
     *     "app_version": "1.0.0"
     * }
     */
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_token' => 'required|string|min:100',
                'device_type' => 'required|in:android,ios',
                'device_name' => 'nullable|string|max:100',
                'app_version' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user('sanctum')->id;
            $organizationId = $request->organization_id;

            // Validate token format
            if (!$this->firebaseService->validateToken($request->device_token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid device token format'
                ], 422);
            }

            // Check if token already exists
            $existingToken = DeviceToken::where('device_token', $request->device_token)->first();

            if ($existingToken) {
                // Update existing token
                $existingToken->update([
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'device_type' => $request->device_type,
                    'device_name' => $request->device_name,
                    'app_version' => $request->app_version,
                    'is_active' => 1,
                    'last_used_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Device token updated successfully',
                    'data' => [
                        'token_id' => $existingToken->id
                    ]
                ], 200);
            }

            // Create new token
            $deviceToken = DeviceToken::create([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'device_token' => $request->device_token,
                'device_type' => $request->device_type,
                'device_name' => $request->device_name,
                'app_version' => $request->app_version,
                'is_active' => 1,
                'last_used_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Device token registered successfully',
                'data' => [
                    'token_id' => $deviceToken->id
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register device token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unregister device token (on logout)
     * POST /api/v1/device-token/unregister
     * 
     * Body:
     * {
     *     "device_token": "fcm_token_here"
     * }
     */
    public function unregister(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user('sanctum')->id;
            $organizationId = $request->organization_id;

            // Deactivate token
            $updated = DeviceToken::where('device_token', $request->device_token)
                ->where('user_id', $userId)
                ->where('organization_id', $organizationId)
                ->update(['is_active' => 0]);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Device token unregistered successfully'
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Device token not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unregister device token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update last used timestamp (heartbeat)
     * POST /api/v1/device-token/heartbeat
     * 
     * Body:
     * {
     *     "device_token": "fcm_token_here"
     * }
     */
    public function heartbeat(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user('sanctum')->id;
            $organizationId = $request->organization_id;

            // Update last_used_at
            $updated = DeviceToken::where('device_token', $request->device_token)
                ->where('user_id', $userId)
                ->where('organization_id', $organizationId)
                ->update(['last_used_at' => now()]);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Heartbeat updated'
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Device token not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update heartbeat',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all registered devices for current user
     * GET /api/v1/device-token/list
     */
    public function list(Request $request)
    {
        try {
            $userId = $request->user('sanctum')->id;
            $organizationId = $request->organization_id;

            $devices = DeviceToken::where('user_id', $userId)
                ->where('organization_id', $organizationId)
                ->orderBy('last_used_at', 'desc')
                ->get()
                ->map(function ($device) {
                    return [
                        'id' => $device->id,
                        'device_type' => $device->device_type,
                        'device_name' => $device->device_name,
                        'app_version' => $device->app_version,
                        'is_active' => $device->is_active,
                        'last_used_at' => $device->last_used_at,
                        'registered_at' => $device->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'devices' => $devices
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch devices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test notification
     * POST /api/v1/device-token/test
     * 
     * Body:
     * {
     *     "device_token": "fcm_token_here"
     * }
     */
    public function test(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->firebaseService->sendToDevice(
                $request->device_token,
                'Test Notification',
                'This is a test notification from Chatberry',
                ['type' => 'test']
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully',
                    'data' => $result
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
                'error' => $result['error'] ?? 'Unknown error'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}