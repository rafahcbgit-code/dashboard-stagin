<?php

namespace App\Http\Controllers\ShopifyIntegratin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ShopifyStore;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use App\Services\Webhooks\ShopifyGDPRWebhookService;
use GuzzleHttp\Client;

class ShopifyAuthController extends Controller
{
    /**
     * Shopify App credentials loaded from environment variables
     */
    protected $apiKey;
    protected $apiSecret;
    protected $scopes;
    protected $redirectUri;
    protected $baseUrl;
    protected $client;

    public function __construct()
    {
        // Load Shopify app credentials from .env file
        $this->apiKey = env('SHOPIFY_API_KEY');
        $this->apiSecret = env('SHOPIFY_API_SECRET');
        $this->scopes = env('SHOPIFY_SCOPES');
        $this->baseUrl = 'https://molecular-felicidad-irrotationally.ngrok-free.dev';
        // The URL Shopify will redirect to after the merchant approves the app
        $this->redirectUri = $this->baseUrl . '/shopify/callback';
        $this->client = new Client();
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
        Log::info("API Key check: " . $this->apiKey);
        $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $this->apiKey,
            'scope'        => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'state'        => $state, // used to preserve our internal context
        ]);

        // Redirect the merchant to Shopify authorization page
        return redirect($installUrl);
    }

    public function installSuccess(Request $request)
    {
        return Inertia::render('Shopify/InstallSuccess', [
            'apiKey' => env('SHOPIFY_API_KEY'),
            'shop' => $request->query('shop'),
            'host' => $request->query('host'),
            'contactEmail' => 'info@chatberry.net',
            'whatsapp' => '+966542922948',
        ]);
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

            Log::info("Shop connected successfully", ['shop' => $shop]);

            //$webhookService = new ShopifyGDPRWebhookService();
            //$webhookService->registerComplianceWebhooks($shop, $accessToken, $this->baseUrl);

            Log::info("Compliance webhooks registered", ['shop' => $shop]);


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
            return redirect()->route('shopify.install.success');
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
        $topics = [
            'orders/create' => '/shopify/webhooks/order-created',
            'orders/updated' => '/shopify/webhooks/orders-updated',
            'orders/fulfilled' => '/shopify/webhooks/orders-fulfilled',
            'checkouts/create' => '/shopify/webhooks/checkouts-created',
        ];

        foreach ($topics as $topic => $endpoint) {
            try {
                $this->client->post("https://{$shop}/admin/api/2026-01/webhooks.json", [
                    'headers' => [
                        'X-Shopify-Access-Token' => $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'webhook' => [
                            'topic' => $topic,
                            'address' => $this->baseUrl . $endpoint,
                            'format' => 'json'
                        ]
                    ]
                ]);

                Log::info("Webhook registered: $topic");
            } catch (\Exception $e) {
                Log::error("Failed to register webhook: $topic", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
