<?php

namespace App\Http\Controllers\ShopifyIntegratin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ShopifyStore;
use App\Http\Controllers\Controller;

class ShopifyAuthController extends Controller
{
    /**
     * Shopify App credentials loaded from environment variables
     */
    protected $apiKey;
    protected $apiSecret;
    protected $scopes;
    protected $redirectUri;

    public function __construct()
    {
        // Load Shopify app credentials from .env file
        $this->apiKey = env('SHOPIFY_API_KEY');
        $this->apiSecret = env('SHOPIFY_API_SECRET');
        $this->scopes = env('SHOPIFY_SCOPES');

        // The URL Shopify will redirect to after the merchant approves the app
        $this->redirectUri = "http://localhost:8000/shopify/callback";
    }

    /**
     * Route: /new-install
     *
     * This method starts the Shopify OAuth installation flow.
     * It redirects the merchant to Shopify's authorization page.
     */
    public function install(Request $request)
    {
        // Shopify shop domain (example: mystore.myshopify.com)
        $shop = $request->query('shop');

        // Try to get the organization ID either from the request or the session
        // This allows linking the Shopify store with a specific organization in our system
        $organizationId = $request->query('org_id') ?? session()->get('current_organization');

        Log::info("Shopify Installation Started", [
            'shop' => $shop,
            'org_id' => $organizationId
        ]);

        // If no shop domain is provided we cannot continue
        if (!$shop) {
            return response()->json(['error' => 'Shop domain is required'], 400);
        }

        /**
         * Build the OAuth "state" parameter.
         *
         * Shopify will send this value back to us in the callback.
         * We use it to remember which organization initiated the install.
         *
         * If no organization exists we mark it as "public".
         */
        $state = $organizationId ? (string)$organizationId : 'public';

        /**
         * Build the Shopify OAuth authorization URL.
         *
         * The merchant will be redirected to this URL to approve the app.
         */
        $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $this->apiKey,
            'scope'        => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'state'        => $state, // used to preserve our internal context
        ]);

        // Redirect the merchant to Shopify authorization page
        return redirect($installUrl);
    }

    /**
     * Route: /shopify/callback
     *
     * Shopify redirects to this endpoint after the merchant approves the app.
     * Here we verify the request, exchange the code for an access token,
     * and store the shop credentials in our database.
     */
    public function callback(Request $request)
    {
        /**
         * Extract only Shopify parameters.
         *
         * Important: if extra parameters are included (like organization from middleware),
         * HMAC verification will fail.
         */
        $params = $request->only(['code', 'hmac', 'host', 'shop', 'state', 'timestamp']);

        Log::info("Shopify Callback Initiated", ['params' => $params]);

        /**
         * Step 1: Verify HMAC signature.
         *
         * Shopify signs every OAuth callback request.
         * This ensures the request actually came from Shopify and is secure.
         */
        if (!$this->verifyHmac($params)) {
            Log::error("Shopify Security Alert: HMAC validation failed. Check if API Secret is correct.", [
                'received_params' => $params
            ]);

            return response()->json(['error' => 'HMAC validation failed'], 401);
        }

        // Extract necessary parameters for the exchange
        $shop = $params['shop'];
        $code = $params['code'];
        $state = $params['state'] ?? 'public';

        /**
         * Step 2: Exchange temporary authorization code for a permanent access token.
         */
        Log::info("Exchanging code for access token...", ['shop' => $shop]);

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id'     => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'code'          => $code,
        ]);

        if ($response->successful()) {

            $data = $response->json();
            $accessToken = $data['access_token'];

            /**
             * Step 3: Map the installation to a ChatBerry Organization.
             * If the install was triggered from the dashboard, $state contains the ID.
             */
            $organizationId = ($state !== 'public' && is_numeric($state)) ? (int)$state : null;

            /**
             * Step 4: Save or update the Shopify store in our database.
             *
             * updateOrCreate ensures we don't duplicate store entries.
             */
            ShopifyStore::updateOrCreate(
                ['shop_url' => $shop],
                [
                    'access_token'    => $accessToken,
                    'status'          => 'active',
                    'organization_id' => $organizationId,
                ]
            );

            Log::info("Shopify Access Token Acquired and Store Saved", [
                'shop' => $shop,
                'org_id' => $organizationId
            ]);

            /**
             * Step 5: Webhook Registration.
             * * Automatically subscribe the store to 'orders/create' events.
             * This allows ChatBerry to process new orders in real-time.
             */
            $this->registerWebhooks($shop, $accessToken);

            /**
             * Step 6: Final Redirection.
             *
             * If an organization ID is present, we return the user to the dashboard settings.
             */
            if ($organizationId) {
                return redirect()->route('settings.plugins')
                    ->with('success', 'Shopify store linked successfully!');
            }

            // Fallback for public installs (e.g., directly from Shopify App Store)
            return response()->json([
                'message' => 'App installed successfully in ' . $shop
            ], 200);
        }

        /**
         * Error Handling: If access token exchange failed.
         */
        Log::error("Shopify Token Exchange Failed", [
            'shop'     => $shop,
            'status'   => $response->status(),
            'response' => $response->body()
        ]);

        return response()->json(['error' => 'Failed to retrieve access token'], 400);
    }

    /**
     * Verify Shopify HMAC signature
     *
     * This ensures the callback request has not been tampered with.
     */
    private function verifyHmac($params)
    {
        // If HMAC is missing the request is invalid
        if (!isset($params['hmac'])) {
            return false;
        }

        $hmac = $params['hmac'];

        // Remove HMAC before calculating our own signature
        unset($params['hmac']);

        /**
         * Shopify requires parameters to be sorted alphabetically
         * before generating the HMAC.
         */
        ksort($params);

        /**
         * Build the query string manually to avoid incorrect encoding.
         */
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $queryString = implode('&', $pairs);

        /**
         * Generate HMAC using SHA256 and the Shopify API secret
         */
        $computedHmac = hash_hmac('sha256', $queryString, $this->apiSecret);

        /**
         * Use hash_equals to prevent timing attacks
         */
        return hash_equals($hmac, $computedHmac);
    }

    private function registerWebhooks($shop, $accessToken)
    {
        $url = "https://{$shop}/admin/api/2024-01/webhooks.json";
        $topics = [
            'orders/create',   // عند إنشاء طلب جديد (لإرسال رسالة ترحيب/تأكيد)
            'orders/updated',  // عند أي تعديل على الطلب أو تغيير حالته العامة
            'orders/fulfilled' ,// عند شحن الطلب (لإرسال رقم التتبع للعميل)
            'checkouts/create'
        ];

        foreach ($topics as $topic) {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
        ])->post($url, [
            'webhook' => [
                'topic'   => $topic, 
                'address' => 'https://molecular-felicidad-irrotationally.ngrok-free.dev/shopify/webhooks/order-created', // رابط الاستقبال لديك
                'format' => 'json'
            ]
        ]);

       if ($response->successful()) {
            Log::info("Webhook [{$topic}] registered successfully for $shop");
        } else {
            Log::error("Failed to register webhook [{$topic}] for $shop", [
                'response' => $response->body()
            ]);
        }
        }
    }
}
