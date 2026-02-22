<?php

namespace App\Http\Controllers\User;

use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Models\SallaStore;
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
     * Redirect to Salla OAuth page
     */
    public function auth()
    {
        $clientId = env('SALLA_CLIENT_ID');
        $redirectUri = route('salla.callback');
        $state = base64_encode(json_encode([
            'organization_id' => session()->get('current_organization'),
            'timestamp' => time()
        ]));

        $authUrl = "https://accounts.salla.sa/oauth2/authorize?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'offline_access'
        ]);

        return redirect($authUrl);
    }

    /**
     * Handle Salla OAuth callback
     */
    public function callback(Request $request)
    {
        try {
            $code = $request->get('code');
            $state = json_decode(base64_decode($request->get('state')), true);
            
            if (!$code) {
                return redirect('/settings/salla')->with('error', __('Authorization failed'));
            }

            // Exchange code for access token
            $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
                'client_id' => env('SALLA_CLIENT_ID'),
                'client_secret' => env('SALLA_CLIENT_SECRET'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => route('salla.callback')
            ]);

            if (!$response->successful()) {
                Log::error('Salla token exchange failed', ['response' => $response->body()]);
                return redirect('/settings/salla')->with('error', __('Failed to connect with Salla'));
            }

            $tokenData = $response->json();

            // Get merchant info
            $merchantInfo = Http::withToken($tokenData['access_token'])
                ->get('https://api.salla.dev/admin/v2/oauth2/user/info')
                ->json();

            // Store or update the Salla store data
            SallaStore::updateOrCreate(
                [
                    'merchant_id' => $merchantInfo['merchant']['id'],
                    'organization_id' => $state['organization_id']
                ],
                [
                    'merchant_name' => $merchantInfo['merchant']['name'] ?? null,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'expires_at' => now()->addSeconds($tokenData['expires_in']),
                    'scopes' => json_encode($tokenData['scope'] ?? [])
                ]
            );

            return redirect('/settings/salla')->with('success', __('Successfully connected to Salla'));

        } catch (\Exception $e) {
            Log::error('Salla callback error', ['error' => $e->getMessage()]);
            return redirect('/settings/salla')->with('error', __('An error occurred while connecting to Salla'));
        }
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
     * Refresh access token
     */
    public function refreshToken($id)
    {
        try {
            $organizationId = session()->get('current_organization');
            
            $store = SallaStore::where('id', $id)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$store) {
                return back()->with('error', __('Store not found'));
            }

            $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
                'client_id' => env('SALLA_CLIENT_ID'),
                'client_secret' => env('SALLA_CLIENT_SECRET'),
                'refresh_token' => $store->refresh_token,
                'grant_type' => 'refresh_token'
            ]);

            if (!$response->successful()) {
                Log::error('Salla token refresh failed', ['response' => $response->body()]);
                return back()->with('error', __('Failed to refresh token'));
            }

            $tokenData = $response->json();

            $store->update([
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_at' => now()->addSeconds($tokenData['expires_in'])
            ]);

            return back()->with('success', __('Token refreshed successfully'));

        } catch (\Exception $e) {
            Log::error('Salla refresh token error', ['error' => $e->getMessage()]);
            return back()->with('error', __('An error occurred'));
        }
    }
}
