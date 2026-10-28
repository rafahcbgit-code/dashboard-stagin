<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Login API for Mobile
     * POST /api/v1/auth/login
     */
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user (exclude super admin, only users with role 'user')
        $user = User::where('email', $request->email)
            ->where('role', 'user')
            ->whereNull('deleted_at')
            ->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password'
            ], 401);
        }

        // Check if user is active
        if ($user->status != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact support.'
            ], 403);
        }

        // Get user's organizations with roles
        $organizations = Team::with('organization')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($team) {
                return [
                    'id' => $team->organization->id,
                    'uuid' => $team->organization->uuid,
                    'name' => $team->organization->name,
                    'role' => $team->role, // owner, manager, agent
                    'status' => $team->status,
                ];
            });

        // Check if user belongs to any organization
        if ($organizations->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not associated with any organization. Please contact your administrator.'
            ], 403);
        }

        // Create personal access token using Sanctum
        $token = $user->createToken('mobile-app', ['*'])->plainTextToken;
        $user->recordLogin();

        // Success response
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                    'role' => $user->role,
                ],
                'organizations' => $organizations,
            ]
        ], 200);
    }

    /**
     * Get current authenticated user
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        try {
            // ✅ Explicitly use Sanctum guard
            $user = $request->user('sanctum');
            
            // Check if user exists
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Invalid or expired token'
                ], 401);
            }

            // Get organizations
            $organizations = Team::with('organization')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->get()
                ->map(function ($team) {
                    return [
                        'id' => $team->organization->id,
                        'uuid' => $team->organization->uuid,
                        'name' => $team->organization->name,
                        'role' => $team->role,
                        'status' => $team->status,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'uuid' => $user->uuid,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'full_name' => $user->full_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'avatar' => $user->avatar,
                        'role' => $user->role,
                    ],
                    'organizations' => $organizations,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout - Revoke current token
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        try {
            // Get user from Sanctum guard
            $user = $request->user('sanctum');
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Invalid or missing token'
                ], 401);
            }

            // Revoke current access token
            $request->user('sanctum')->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's organizations (for selection screen)
     * GET /api/v1/auth/organizations
     */
    public function organizations(Request $request)
    {
        try {
            // Get user from Sanctum guard
            $user = $request->user('sanctum');
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Get all organizations the user belongs to
            $organizations = Team::with('organization.subscription.plan')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->get()
                ->map(function ($team) {
                    $organization = $team->organization;
                    $subscription = $organization->subscription;
                    
                    return [
                        'id' => $organization->id,
                        'uuid' => $organization->uuid,
                        'name' => $organization->name,
                        'identifier' => $organization->identifier,
                        'role' => $team->role, // owner, manager, agent
                        'status' => $team->status, // active, inactive
                        'subscription' => $subscription ? [
                            'status' => $subscription->status, // active, trial, expired
                            'plan_name' => $subscription->plan ? $subscription->plan->name : 'Free',
                            'valid_until' => $subscription->valid_until,
                        ] : null,
                    ];
                });

            if ($organizations->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not associated with any organization'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'organizations' => $organizations
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch organizations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
