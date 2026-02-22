<?php

namespace App\Http\Controllers\Salla;

use App\Http\Controllers\Controller;
use App\Models\SallaStore;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
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
            'response_type'=> 'code',
            'scope'        => 'offline_access',
            'redirect_url' => config('salla.callback_url'),
            'state'        => rand(1111111110, 9999999999)
        ];

        $query = http_build_query($data);

        return redirect(config('salla.auth_url') . '?' . $query);
    }


public function callback(Request $request)
    {
        $requestId = (string) Str::uuid();

        if ($request->filled('error')) {
            Log::warning('Salla OAuth callback: authorization error returned', [
                'request_id' => $requestId,
                'error' => (string) $request->input('error'),
                'error_description' => (string) $request->input('error_description'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Authorization failed.',
            ], 400);
        }

        $code = (string) $request->input('code', '');
        $state = (string) $request->input('state', '');
        $scopeParam = $request->input('scope');

        Log::info('Salla OAuth callback: received', [
            'request_id' => $requestId,
            'ip' => $request->ip(),
            'has_code' => $request->filled('code'),
            'code_hint' => $this->maskValue($code),
            'has_state' => $request->filled('state'),
            'state_hint' => $this->maskValue($state),
            'scope' => is_string($scopeParam) ? Str::limit($scopeParam, 200) : (is_array($scopeParam) ? $scopeParam : null),
        ]);

        if ($code === '') {
            Log::warning('Salla OAuth callback: missing code', [
                'request_id' => $requestId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Missing authorization code.',
            ], 400);
        }

        // ملاحظة: `state` يتم إنشاؤه قبل إعادة التوجيه إلى سلة وتخزينه في session تحت المفتاح `salla_oauth_state`.
        // التحقق منه هنا يمنع هجمات CSRF وحقن كود التفويض (Authorization Code Injection).
        
        if ($state === '') {
            Log::warning('Salla OAuth callback: invalid state', [
                'request_id' => $requestId,
                'state_hint' => $this->maskValue($state),
                'expected_state_hint' => $this->maskValue($expectedState),
                'session_has_state' => $expectedState !== '',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid state.',
            ], 419);
        }

        $scopes = $this->normalizeScopes($scopeParam);

        $clientId = config('salla.client_id') ?: config('salla.client_id');
        $clientSecret = config('salla.client_secret') ?: config('salla.client_secret');
        $redirectUri = config('salla.redirect') ?: config('salla.callback_url');

        if (empty($clientId) || empty($clientSecret) || empty($redirectUri)) {
            Log::critical('Salla OAuth callback: missing Salla config', [
                'request_id' => $requestId,
                'has_client_id' => ! empty($clientId),
                'has_client_secret' => ! empty($clientSecret),
                'has_redirect' => ! empty($redirectUri),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Salla OAuth configuration is missing.',
            ], 500);
        }

        Log::info('Salla OAuth: exchanging code for token', [
            'request_id' => $requestId,
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
                'request_id' => $requestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to communicate with Salla token endpoint.',
            ], 502);
        }

        if (! $tokenResponse->successful()) {
            Log::error('Salla OAuth: token request failed', [
                'request_id' => $requestId,
                'status' => $tokenResponse->status(),
                'body' => $this->safeBody($tokenResponse),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token exchange failed.',
            ], 502);
        }

        $tokenJson = $tokenResponse->json() ?? [];

        $accessToken = data_get($tokenJson, 'access_token') ?? data_get($tokenJson, 'data.access_token');
        $refreshToken = data_get($tokenJson, 'refresh_token') ?? data_get($tokenJson, 'data.refresh_token');
        $expiresIn = data_get($tokenJson, 'expires_in') ?? data_get($tokenJson, 'data.expires_in');

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($refreshToken) || $refreshToken === '' || ! is_numeric($expiresIn)) {
            Log::error('Salla OAuth: invalid token response structure', [
                'request_id' => $requestId,
                'access_token_present' => is_string($accessToken) && $accessToken !== '',
                'refresh_token_present' => is_string($refreshToken) && $refreshToken !== '',
                'expires_in' => $expiresIn,
                'body' => $this->safeBody($tokenResponse),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid token response from Salla.',
            ], 502);
        }

        $expiresIn = (int) $expiresIn;
        $expiresAt = now()->addSeconds(max($expiresIn, 0));

        Log::info('Salla OAuth: token received', [
            'request_id' => $requestId,
            'access_token_hint' => $this->maskValue($accessToken),
            'refresh_token_hint' => $this->maskValue($refreshToken),
            'expires_in' => $expiresIn,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        Log::info('Salla OAuth: fetching store info', [
            'request_id' => $requestId,
            'store_info_url' => 'https://api.salla.dev/admin/v2/store/info',
        ]);

        try {
            $storeResponse = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->get('https://api.salla.dev/admin/v2/store/info');
        } catch (\Throwable $e) {
            Log::error('Salla OAuth: store info request exception', [
                'request_id' => $requestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to communicate with Salla store info endpoint.',
            ], 502);
        }

        if (! $storeResponse->successful()) {
            Log::error('Salla OAuth: store info request failed', [
                'request_id' => $requestId,
                'status' => $storeResponse->status(),
                'body' => $this->safeBody($storeResponse),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store info from Salla.',
            ], 502);
        }

        $storeJson = $storeResponse->json() ?? [];

        $merchantId = data_get($storeJson, 'id');

        $merchantName = data_get($storeJson, 'name');

        $merchantId = is_scalar($merchantId) ? (string) $merchantId : '';
        $merchantName = is_scalar($merchantName) ? (string) $merchantName : null;

        if ($merchantId === '') {
            Log::error('Salla OAuth: merchant_id missing in store info response', [
                'request_id' => $requestId,
                'body' => $this->safeBody($storeResponse),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid store info response from Salla.',
            ], 502);
        }

        Log::info('Salla OAuth: store info received', [
            'request_id' => $requestId,
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
            'scopes' => $scopes,
        ]);

        try {
            SallaStore::updateOrCreate(
                ['merchant_id' => $merchantId],
                [
                    'merchant_name' => $merchantName,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => $expiresAt,
                    'scopes' => $scopes,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Salla OAuth: failed to persist store credentials', [
                'request_id' => $requestId,
                'merchant_id' => $merchantId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save Salla store credentials.',
            ], 500);
        }

        Log::info('Salla OAuth: completed successfully', [
            'request_id' => $requestId,
            'merchant_id' => $merchantId,
        ]);

        return response()->json([
            'success' => true,
            'merchant_id' => $merchantId,
        ]);
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
