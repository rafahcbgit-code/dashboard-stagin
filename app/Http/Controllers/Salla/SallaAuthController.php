<?php

namespace App\Http\Controllers\Salla;

use App\Http\Controllers\Controller;
use App\Models\SallaStore;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SallaAuthController extends Controller
{
    public function storeAuth()
    {
        $data = [
            'client_id'     => config('salla.client_id'),
            'client_secret' => config('salla.client_secret'),
            'response_type' => 'code',
            'scope'        => 'offline_access',
            'redirect_url' => config('salla.callback_url'),
            'state'        => rand(1111111110, 9999999999)
        ];

        $query = http_build_query($data);

        return redirect(config('salla.auth_url') . '?' . $query);
    }


    public function callback(Request $request)
    {
        $organizationId = (int) $request->session()->get('current_organization');
        if ($request->filled('error')) {
            Log::warning('Salla OAuth callback: authorization error returned', [
                'error' => (string) $request->input('error'),
                'error_description' => (string) $request->input('error_description'),
            ]);

            return redirect('/settings/salla')->with('error', __('Authorization was denied or cancelled.'));
        }

        $code = (string) $request->input('code', '');
        $state = (string) $request->input('state', '');
        $scopeParam = $request->input('scope');

        Log::info('Salla OAuth callback: received', [
            'has_code' => $request->filled('code'),
            'has_state' => $request->filled('state'),
            'scope' => is_string($scopeParam) ? Str::limit($scopeParam, 200) : (is_array($scopeParam) ? $scopeParam : null),
        ]);

        if ($code === '') {
            return redirect('/settings/salla')->with('error', __('Missing authorization code.'));
        }

        if ($state === '') {
            return redirect('/settings/salla')->with('error', __('Invalid or expired session. Please try again.'));
        }

        $scopes = $this->normalizeScopes($scopeParam);

        $clientId = config('salla.client_id');
        $clientSecret = config('salla.client_secret');
        $redirectUri = config('salla.redirect');

        if (empty($clientId) || empty($clientSecret) || empty($redirectUri)) {
            Log::critical('Salla OAuth callback: missing Salla config', [
                'has_client_id' => ! empty($clientId),
                'has_client_secret' => ! empty($clientSecret),
                'has_redirect' => ! empty($redirectUri),
            ]);

            return redirect('/settings/salla')->with('error', __('Salla integration is not configured properly.'));
        }

        Log::info('Salla OAuth: exchanging code for token', [
            'token_url' => 'https://accounts.salla.sa/oauth2/token',
            'client_id_hint' => $this->maskValue((string) $clientId),
            'redirect_uri' => (string) $redirectUri,
        ]);

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->post('https://accounts.salla.sa/oauth2/token', [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ]);
        } catch (\Throwable $e) {
            Log::error('Salla OAuth: token request exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect('/settings/salla')->with('error', __('Failed to connect to Salla. Please try again.'));
        }

        if (! $tokenResponse->successful()) {
            Log::error('Salla OAuth: token request failed', [
                'status' => $tokenResponse->status(),
                'body' => $this->safeBody($tokenResponse),
            ]);

            return redirect('/settings/salla')->with('error', __('Failed to get access token from Salla.'));
        }

        $tokenJson = $tokenResponse->json() ?? [];

        $accessToken = data_get($tokenJson, 'access_token') ?? data_get($tokenJson, 'data.access_token');
        $refreshToken = data_get($tokenJson, 'refresh_token') ?? data_get($tokenJson, 'data.refresh_token');
        $expiresIn = data_get($tokenJson, 'expires_in') ?? data_get($tokenJson, 'data.expires_in');

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($refreshToken) || $refreshToken === '' || ! is_numeric($expiresIn)) {

            return redirect('/settings/salla')->with('error', __('Invalid response from Salla.'));
        }

        $expiresIn = (int) $expiresIn;
        $expiresAt = now()->addSeconds(max($expiresIn, 0));

        try {
            $storeResponse = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->get('https://api.salla.dev/admin/v2/store/info');
        } catch (\Throwable $e) {
            Log::error('Salla OAuth: store info request exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect('/settings/salla')->with('error', __('Failed to fetch store information from Salla.'));
        }

        if (! $storeResponse->successful()) {
            Log::error('Salla OAuth: store info request failed', [
                'status' => $storeResponse->status(),
                'body' => $this->safeBody($storeResponse),
            ]);

            return redirect('/settings/salla')->with('error', __('Failed to fetch store information from Salla.'));
        }

        $storeJson = $storeResponse->json() ?? [];

        $merchantId = data_get($storeJson, 'data.id');

        $merchantName = data_get($storeJson, 'data.name');

        $merchantId = is_scalar($merchantId) ? (string) $merchantId : '';
        $merchantName = is_scalar($merchantName) ? (string) $merchantName : null;

        if ($merchantId === '') {
            Log::error('Salla OAuth: merchant_id missing in store info response', [
                'body' => $this->safeBody($storeResponse),
            ]);

            return redirect('/settings/salla')->with('error', __('Invalid store information received from Salla.'));
        }

        Log::info('Salla OAuth: store info received', [
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
            'scopes' => $scopes,
        ]);

        try {
            SallaStore::updateOrCreate(
                [
                    'merchant_id' => $merchantId,
                    'organization_id' => $organizationId
                ],
                [
                    'merchant_name' => $merchantName,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => $expiresAt,
                    'refresh_token_expires_at' => now()->addDays(30), // Refresh token valid for 30 days
                    'refresh_token_used_at' => null, // Reset on new connection
                    'scopes' => $scopes,
                    'token_version' => DB::raw('COALESCE(token_version, 0) + 1'), // Increment version
                    'is_revoked' => false, // Reset revocation status
                    'revoked_at' => null,
                    'revocation_reason' => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Salla OAuth: failed to persist store credentials', [
                'merchant_id' => $merchantId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect('/settings/salla')->with('error', __('Failed to save store credentials.'));
        }

        Log::info('Salla OAuth: completed successfully', [
            'merchant_id' => $merchantId,
        ]);

        return redirect('/settings/salla')->with('success', __('Successfully connected to Salla store!'));
    }

    private function normalizeScopes(mixed $scope): array
    {
        if (is_array($scope)) {
            $items = $scope;
        } elseif (is_string($scope)) {
            $scope = str_replace('+', ' ', $scope);
            $items = preg_split('/[\\s,]+/', trim($scope)) ?: [];
        } else {
            $items = [];
        }

        $items = array_values(array_filter(array_map(static function ($value) {
            $value = is_string($value) ? trim($value) : '';
            return $value !== '' ? $value : null;
        }, $items)));

        return array_values(array_unique($items));
    }

    private function maskValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        $len = strlen($value);

        if ($len === 0) {
            return '';
        }

        if ($len <= 10) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 6) . '...' . substr($value, -4);
    }

    private function safeBody(Response $response, int $limit = 2000): string
    {
        return Str::limit((string) $response->body(), $limit);
    }
}
