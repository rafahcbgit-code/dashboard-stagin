<?php

namespace App\Http\Controllers\Zid;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZidAuthController extends Controller
{
    const OAUTH_URL = 'https://oauth.zid.sa/';

    public function redirectToProvider()
    {
        $organizationId = session()->get('current_organization');
        $data = [
            'organization_id' => $organizationId,
            'random' => Str::random(20),
        ];

        $state = encrypt(json_encode($data));
        $queries = http_build_query([
            'client_id' => config('zid.client_id'),
            'redirect_uri' => config('zid.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        Log::channel('zid')->info('Zid OAuth start from redirectToProvider', ['organization_id' => $organizationId]);

        return redirect(config('zid.auth_url') . '?' . $queries);
    }
   
    public function handleCallback(Request $request)
    {
        Log::channel('zid')->info('Zid Callback START', [
            'full_url' => $request->fullUrl(),
            'params' => $request->all(),
        ]);

        // في حال المستخدم رفض التفويض
        if ($request->filled('error')) {
            Log::channel('zid')->warning('Zid OAuth: authorization denied', [
                'error' => $request->input('error')
            ]);

            return response()->json([
                'success' => false,
                'message' => __('Authorization was denied or cancelled.')
            ], 403);
        }

        /**
         * ✅ استخراج organization_id من state المشفّر
         */
        try {
            $decoded = json_decode(decrypt($request->state), true);
            $organizationId = $decoded['organization_id'] ?? null;

            if (!$organizationId) {
                throw new \Exception('Missing organization_id in state');
            }
        } catch (\Throwable $e) {
            Log::channel('zid')->warning('Zid Callback: Invalid state', [
                'state' => $request->state,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('Invalid or expired Zid session.')
            ], 400);
        }

        $code = $request->input('code');

        if (!$code) {
            Log::channel('zid')->warning('Zid Callback: Missing code');

            return response()->json([
                'success' => false,
                'message' => __('Missing authorization code.')
            ], 400);
        }

        Log::channel('zid')->info('Zid Callback: Code received', ['code' => $code]);

        /**
         * ✅ جلب التوكن
         */
        $tokenResponse = $this->exchangeCodeForToken($code);

        if (!$tokenResponse['success']) {
            Log::channel('zid')->error('Zid Callback: Token exchange failed', [
                'response' => $tokenResponse
            ]);

            return response()->json([
                'success' => false,
                'message' => $tokenResponse['message']
            ], 400);
        }

        $payload = $tokenResponse['payload'];

        Log::channel('zid')->info('Zid Callback: Token received', [
            'payload' => $payload
        ]);

        $authorizationToken = data_get($payload, 'Authorization') ?? data_get($payload, 'authorization');
        $managerToken       = data_get($payload, 'access_token');
        $refreshToken       = data_get($payload, 'refresh_token');
        $expiresIn          = data_get($payload, 'expires_in');


         Log::channel('zid')->error('Zid OAuth: authorizationToken', [
                'authorizationToken' => $authorizationToken,
                'managerToken' => $managerToken
            ]);


        if (!$authorizationToken || !$managerToken || !is_numeric($expiresIn)) {
            Log::channel('zid')->error('Zid OAuth: token payload missing required fields', [
                'payload' => $payload,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to read Zid tokens.')
            ], 400);
        }

        /**
         * ✅ جلب بيانات المتجر
         */
        $profile = $this->fetchManagerProfile($authorizationToken, $managerToken);


       

        if (!is_string($storeId) || $storeId === '') {
            $storeId = (string) Str::uuid();
        }

        /**
         * ✅ حفظ الاتصال
         */
        $connection = IntegrationConnection::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'platform' => config('zid.platform'),
            ],
            [
                'store_id' => $storeId,
                'store_name' => $storeName ?? "store",
                'authorization_token' => $authorizationToken,
                'manager_token' => $managerToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds((int) $expiresIn),
                'connection_status' => 'connected',
                'connected_at' => now(),
                'disconnected_at' => null,
            ]
        );

        Log::channel('zid')->info('Zid Callback SUCCESS - Connection stored', [
            'organization_id' => $organizationId,
            'connection info' => $connection
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Successfully connected to your Zid store.'),
            'connection' => [
                'organization_id' => $connection->organization_id,
                'store_id' => $connection->store_id,
                'store_name' => $connection->store_name,
            ],
        ]);
    }

    public static function getTokensFromZidByCode(string $oauthCode): array
    {
        $url = self::OAUTH_URL . 'oauth/token';
        $requestBody = [
            'grant_type' => 'authorization_code',
            'client_id' => config('zid.client_id'),
            'client_secret' => config('zid.client_secret'),
            'redirect_uri' => config('zid.redirect_uri'),
            'code' => $oauthCode,
        ];

        $response = Http::post($url, $requestBody);

        return $response->json();
    }

    public function disconnect(Request $request)
    {
        $organizationId = session()->get('current_organization');

        $connection = IntegrationConnection::where('organization_id', $organizationId)
            ->where('platform', config('zid.platform'))
            ->first();

        if ($connection) {
            $connection->update([
                'connection_status' => 'disconnected',
                'disconnected_at' => now(),
            ]);
        }

        return redirect('/settings/plugins')->with('success', __('Zid store disconnected.'));
    }

    private function exchangeCodeForToken(string $code): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->post(config('zid.token_url'), [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('zid.client_id'),
                    'client_secret' => config('zid.client_secret'),
                    'code' => $code,
                    'redirect_uri' => config('zid.redirect_uri'),
                ]);
        } catch (\Throwable $exception) {
            Log::channel('zid')->error('Zid OAuth: token request failed', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('Failed to connect to Zid. Please try again.'),
            ];
        }

        if (! $response->successful()) {
            Log::channel('zid')->error('Zid OAuth: token endpoint returned error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => __('Failed to retrieve Zid tokens.'),
            ];
        }


        Log::channel('zid')->error('Zid OAuth: token request correct', [
                'message' => $response->json(),
            
            ]);

        return [
            'success' => true,
            'payload' => $response->json() ?? [],
        ];
    }

    private function fetchManagerProfile(string $authorizationToken, string $managerToken): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($authorizationToken)
                ->withHeaders([
                    'X-Manager-Token' => $managerToken,
                ])
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(2, 250, null, false)
                ->get(config('zid.manager_profile_url'));


                 Log::channel('zid')->warning('from fetchManagerProfile function', [
                
                'fetchManagerProfile is ' => $response->json(),
            ]);
        } catch (\Throwable $exception) {
            Log::channel('zid')->warning('Zid OAuth: failed to fetch store profile', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::channel('zid')->warning('Zid OAuth: store profile request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $payload = $response->json('data', []);
        $store = data_get($payload, 'store', []);

        return array_merge($payload ?? [], [
            'store_id' => data_get($store, 'id'),
            'store_name' => data_get($store, 'username'),
            'store_uuid' => data_get($store, 'uuid'),
            'store' => $store,
        ]);
    }

    private function fetchSubscriptionStoreId(string $authorizationToken, string $managerToken): ?string
    {
        $appId = config('zid.client_id');

        $response = Http::acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $authorizationToken,
                'X-Manager-Token' => $managerToken,
            ])
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(2, 250, null, false)
            ->get('https://api.zid.sa/v1/market/app/subscription', [
                'app_id' => $appId,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch Zid subscription store_id.');
        }

        $data = $response->json();

        $subscriptionStoreId = data_get($data, 'subscription.store_id');

        if (! $subscriptionStoreId) {
            return null;
        }

        return (string) $subscriptionStoreId;
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

    public function webhooks(Request $request)
    {
        Log::channel('zid')->info('Zid Webhook received', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);
    }
}
