<?php

namespace App\Http\Controllers\User;

use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Models\SallaStore;
use App\Services\SallaTokenService;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SallaController extends BaseController
{
    /**
     * Display Salla Integration page
     */
    public function index()
    {
        $organizationId = session()->get('current_organization');
        
        $data['title'] = __('Salla Integration');
        $data['stores'] = SallaStore::where('organization_id', $organizationId)->get();
       
        return Inertia::render('User/Settings/Salla', $data);
    }
    
    /**
     * Disconnect/Delete a Salla store
     */
    public function disconnect($id)
    {
        $organizationId = session()->get('current_organization');
        
        $store = SallaStore::where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$store) {
            return back()->with('error', __('Store not found'));
        }

        $store->delete();

        return back()->with('success', __('Store disconnected successfully'));
    }

    /**
     * Refresh access token using secure token service
     */
    public function refreshToken($id, SallaTokenService $tokenService)
    {
        try {
            $organizationId = session()->get('current_organization');
            
            $store = SallaStore::where('id', $id)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$store) {
                return back()->with('error', __('Store not found'));
            }

            // Check if store is revoked
            if ($store->is_revoked) {
                return back()->with('error', __('This store connection has been revoked. Please reconnect your store.'));
            }

            // Check if refresh token is expired
            if ($store->isRefreshTokenExpired()) {
                return back()->with('error', __('Refresh token has expired. Please reconnect your store.'));
            }

            // Use secure token service to refresh (force = true for manual button click)
            $tokenService->refreshAccessToken($store, true);

            // Reload store to get fresh data
            $store->refresh();

            return back()->with('success', __('Token refreshed successfully! New token expires at: ') . $store->expires_at->format('Y-m-d H:i:s'));

        } catch (\Exception $e) {
            Log::error('Salla refresh token error', [
                'error' => $e->getMessage(),
                'store_id' => $id,
                'organization_id' => $organizationId ?? null,
            ]);
            
            return back()->with('error', $e->getMessage());
        }
    }
}
