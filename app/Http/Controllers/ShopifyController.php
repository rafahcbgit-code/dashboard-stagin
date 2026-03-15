<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ShopifyStore;

class ShopifyController extends Controller
{
    protected $apiKey;
    protected $apiSecret;
    protected $scopes;
    protected $redirectUri;

    public function __construct()
    {
        $this->apiKey = env('SHOPIFY_API_KEY');
        $this->apiSecret = env('SHOPIFY_API_SECRET');
        $this->scopes = env('SHOPIFY_SCOPES');
        $this->redirectUri = "http://localhost:8000/shopify/callback";
    }

    /**
     * Route: /new-install
     */
    public function install(Request $request)
    {
        $shop = $request->query('shop');

        Log::info("Shopify Installation Started", ['shop' => $shop]);

        if (!$shop) {
            Log::warning("Installation failed: Missing shop parameter.");
            return response()->json(['error' => 'Shop domain is required'], 400);
        }

        $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $this->apiKey,
            'scope'        => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'state'        => bin2hex(random_bytes(16)),
        ]);

        return redirect($installUrl);
    }

    /**
     * Route: /shopify/callback
     */
    public function callback(Request $request)
    {
         $organizationId = (int) session()->get('current_organization');
        $params = $request->all();
        $shop = $params['shop'] ?? null;
        $code = $params['code'] ?? null;

        Log::info("Shopify Callback Received", ['shop' => $shop, 'params' => $params]);

        // 1. Validate HMAC
        if (!$this->verifyHmac($params)) {
            Log::error("Shopify Security Alert: HMAC validation failed", ['params' => $params]);
            return response()->json(['error' => 'HMAC validation failed'], 401);
        }

        if (!$code || !$shop) {
            Log::error("Shopify Callback Error: Missing code or shop", ['params' => $params]);
            return response()->json(['error' => 'Invalid request parameters'], 400);
        }

        // 2. Exchange Code for Token
        Log::info("Exchanging code for access token...", ['shop' => $shop]);

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id'     => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'code'          => $code,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $accessToken = $data['access_token'];
            Log::info("accessToken Acquired Successfully", ['accessToken' => $accessToken]);

            // Save or update the store information in our database
            ShopifyStore::updateOrCreate(
                ['shop_url' => $shop], // Search by shop URL
                [
                    'access_token' => $accessToken,
                    'status' => 'active',
                    'organization_id' => $organizationId??null,
                ]
            );
            Log::info("Shopify Access Token Acquired Successfully", ['shop' => $shop]);

            // Save to DB logic here

            return response()->json([
                'message' => 'Application installed successfully',
                'shop'    => $shop
            ], 200);
        }

        Log::error("Shopify Token Exchange Failed", [
            'shop'     => $shop,
            'status'   => $response->status(),
            'response' => $response->body()
        ]);

        return response()->json(['error' => 'Failed to retrieve access token'], 400);
    }

    private function verifyHmac($params)
    {
        if (!isset($params['hmac'])) return false;

        $hmac = $params['hmac'];
        unset($params['hmac']);
        ksort($params);

        $computedHmac = hash_hmac('sha256', http_build_query($params), $this->apiSecret);

        return hash_equals($hmac, $computedHmac);
    }
}
