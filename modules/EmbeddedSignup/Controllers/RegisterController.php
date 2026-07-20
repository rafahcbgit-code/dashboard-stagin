<?php

namespace Modules\EmbeddedSignup\Controllers;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\EmbeddedSignup\Services\MetaTokenDiagnosticsService;

class RegisterController extends BaseController
{
    private $apiVersion;

    public function __construct(){
        $this->apiVersion = config('graph.api_version');
    }

    public function handleSignup(Request $request){

        $startTime = microtime(true);
        Log::info("========== SIGNUP FLOW START ==========");
        Log::info("[STEP 0] Request received at: " . now()->toDateTimeString());

        $organizationId = session()->get('current_organization');
        Log::info("[STEP 0] Organization ID: {$organizationId}");

        $isCoexistence = (bool) $request->boolean('coexistence');
        $phoneNumberIdFromUI = $request->input('phone_number_id');
        $wabaIdFromUI = $request->input('waba_id');
        $rawToken = $request->input('token') ?: $request->input('code');

        Log::info("[STEP 0] Coexistence: " . ($isCoexistence ? 'true' : 'false'));
        Log::info("[STEP 0] phone_number_id from UI: " . ($phoneNumberIdFromUI ?: 'NOT PROVIDED'));
        Log::info("[STEP 0] waba_id from UI: " . ($wabaIdFromUI ?: 'NOT PROVIDED'));
        Log::info("[STEP 0] Authorization code received — length: " . strlen($rawToken ?? ''));
        Log::info("[STEP 0] Graph API version: {$this->apiVersion}");

        if (empty($rawToken)) {
            return back()->with('status', [
                'type' => 'error',
                'message' => __('Authorization code is missing. Please try onboarding again.')
            ]);
        }

        // 1) Exchange code for short-lived access token
        Log::info("[STEP 1] Exchanging authorization code for access token...");
        Log::info("[STEP 1] Calling: GET https://graph.facebook.com/{$this->apiVersion}/oauth/access_token");

        $settings = \App\Models\Setting::whereIn('key', [
            'whatsapp_client_id',
            'whatsapp_client_secret',
            'whatsapp_access_token',
        ])->pluck('value', 'key');
        Log::info("[STEP 1] client_id from DB: " . substr($settings->get('whatsapp_client_id', 'NOT SET'), 0, 8) . '...');
        Log::info("[STEP 1] client_secret from DB: " . (empty($settings->get('whatsapp_client_secret')) ? 'EMPTY/NOT SET' : 'SET (hidden)'));

        $accessTokenResponse = $this->getAccessToken($rawToken);
        Log::info("[STEP 1] getAccessToken success: " . ($accessTokenResponse->success ? 'true' : 'false'));

        if (!$accessTokenResponse->success) {
            Log::error("[STEP 1] FAILED — Full error: " . json_encode($accessTokenResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $accessTokenResponse->data->error->message
            ]);
        }

        Log::info("[STEP 1] Access token obtained — length: " . strlen($accessTokenResponse->data->access_token ?? ''));

        $accessToken = $accessTokenResponse->data->access_token;

        // 2) Advanced Access on the app does not guarantee that the token issued by a
        // specific login configuration carries the scopes or WABA asset assignments.
        Log::info("[STEP 2] Inspecting the issued token and its granted scopes...");
        $diagnostics = new MetaTokenDiagnosticsService(
            $this->apiVersion,
            $settings->get('whatsapp_client_id'),
            $settings->get('whatsapp_client_secret'),
            $settings->get('whatsapp_access_token'),
        );

        $tokenInspection = $diagnostics->inspectToken($accessToken);
        Log::info('[STEP 2] Token inspection result', $tokenInspection);

        if (! $tokenInspection['success']) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $this->diagnosticMessage($tokenInspection),
            ]);
        }

        $tokenDetails = $tokenInspection['details'];
        $wabaIdFromToken = $diagnostics->firstWhatsappManagementTarget($tokenInspection);

        Log::info("[STEP 2] app_id from debug_token: " . ($tokenDetails['app_id'] ?? 'NULL'));
        Log::info("[STEP 2] token type: " . ($tokenDetails['type'] ?? 'NULL'));
        Log::info("[STEP 2] WABA target from whatsapp_business_management: " . ($wabaIdFromToken ?: 'NULL'));

        $wabaId = $wabaIdFromUI ?: $wabaIdFromToken;
        if (empty($wabaId)) {
            $wabaId = $this->discoverWabaIdFromBusinesses($accessToken);
            Log::info("[STEP 2] WABA ID fallback from businesses: " . ($wabaId ?: 'NULL'));
        }
        Log::info("[STEP 2] Final waba_id to use: " . ($wabaId ?: 'EMPTY — WILL FAIL'));

        if (empty($wabaId)) {
            Log::error("[STEP 2] FAILED — Could not determine WABA ID");
            return back()->with('status', [
                'type' => 'error',
                'message' => __('Unable to determine WABA ID from signup flow.')
            ]);
        }

        // 2b) Prove access to this exact WABA and its message_templates edge. A
        // comparison against the configured central token distinguishes an invalid
        // endpoint/WABA from an asset-task assignment missing on the issued token.
        $wabaAccessDiagnosis = $diagnostics->diagnoseWabaAccess($accessToken, (string) $wabaId);
        Log::info('[STEP 2B] WABA access diagnosis', $wabaAccessDiagnosis);

        if (! $wabaAccessDiagnosis['success']) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $this->diagnosticMessage($wabaAccessDiagnosis),
            ]);
        }

        // 3) Fetch phone number
        Log::info("[STEP 3] Fetching phone number...");
        if (!empty($phoneNumberIdFromUI)) {
            Log::info("[STEP 3] Using phone_number_id from UI: {$phoneNumberIdFromUI}");
            $phoneNumberResponse = $this->getPhoneNumberById($accessToken, $phoneNumberIdFromUI);
        } else {
            Log::info("[STEP 3] No phone_number_id from UI — fetching first number from WABA: {$wabaId}");
            $phoneNumberResponse = $this->getPhoneNumberId($accessToken, $wabaId);
        }

        Log::info("[STEP 3] getPhoneNumber success: " . ($phoneNumberResponse->success ? 'true' : 'false'));

        if (!$phoneNumberResponse->success) {
            Log::error("[STEP 3] FAILED — Full error: " . json_encode($phoneNumberResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $phoneNumberResponse->data->error->message
            ]);
        }

        $phoneNumberId = $phoneNumberResponse->data->id;
        Log::info("[STEP 3] phone_number_id resolved: {$phoneNumberId}");
        Log::info("[STEP 3] display_phone_number: " . ($phoneNumberResponse->data->display_phone_number ?? 'NULL'));

        // 4) Phone number status
        Log::info("[STEP 4] Getting phone number status for: {$phoneNumberId}");
        $phoneNumberStatusResponse = $this->getPhoneNumberStatus($accessToken, $phoneNumberId);
        Log::info("[STEP 4] success: " . ($phoneNumberStatusResponse->success ? 'true' : 'false'));

        if (!$phoneNumberStatusResponse->success) {
            Log::error("[STEP 4] FAILED — Full error: " . json_encode($phoneNumberStatusResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $phoneNumberStatusResponse->data->error->message
            ]);
        }

        Log::info("[STEP 4] status: " . ($phoneNumberStatusResponse->data->status ?? 'NULL'));
        Log::info("[STEP 4] code_verification_status: " . ($phoneNumberStatusResponse->data->code_verification_status ?? 'NULL'));

        // 5) Account review status
        Log::info("[STEP 5] Getting account review status for WABA: {$wabaId}");
        $accountReviewStatusResponse = $this->getAccountReviewStatus($accessToken, $wabaId);
        Log::info("[STEP 5] success: " . ($accountReviewStatusResponse->success ? 'true' : 'false'));

        if (!$accountReviewStatusResponse->success) {
            Log::error("[STEP 5] FAILED — Full error: " . json_encode($accountReviewStatusResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $accountReviewStatusResponse->data->error->message
            ]);
        }

        Log::info("[STEP 5] account_review_status: " . ($accountReviewStatusResponse->data->account_review_status ?? 'NULL'));

        // 6) Register number (non-coexistence only)
        // Skip if number is already CONNECTED — calling /register on a connected number
        // returns #133015 (cooldown) or #131000 (already registered).
        $currentStatus = $phoneNumberStatusResponse->data->status ?? null;
        if (!$isCoexistence) {
            if ($currentStatus === 'CONNECTED') {
                Log::info("[STEP 6] Skipping registration — number is already CONNECTED");
            } else {
                Log::info("[STEP 6] Registering phone number: {$phoneNumberId} (current status: {$currentStatus})");
                $registerNumber = $this->registerNumber($accessToken, $phoneNumberId);
                Log::info("[STEP 6] registerNumber success: " . ($registerNumber->success ? 'true' : 'false'));

                if (!$registerNumber->success) {
                    Log::error("[STEP 6] FAILED — Full error: " . json_encode($registerNumber->data));
                    return back()->with('status', [
                        'type' => 'error',
                        'message' => $registerNumber->data->error->message
                    ]);
                }

                Log::info("[STEP 6] Number registered successfully");
            }
        } else {
            Log::info("[STEP 6] Skipping registration (coexistence mode)");
        }

        // 7) Business profile
        Log::info("[STEP 7] Getting business profile for phone: {$phoneNumberId}");
        $businessProfileResponse = $this->getBusinessProfile($accessToken, $phoneNumberId);
        Log::info("[STEP 7] success: " . ($businessProfileResponse->success ? 'true' : 'false'));

        if (!$businessProfileResponse->success) {
            Log::error("[STEP 7] FAILED — Full error: " . json_encode($businessProfileResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $businessProfileResponse->data->error->message
            ]);
        }

        // 8) Subscribe app + set webhook
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $callbackUrl = URL::to('/') . '/webhook/whatsapp/' . $organizationConfig->identifier;
        $token = $organizationConfig->identifier;

        Log::info("[STEP 8] Subscribing app to WABA: {$wabaId}");
        $subscribeToWabaResponse = $this->subscribeToWaba($accessToken, $wabaId);
        Log::info("[STEP 8] subscribeToWaba success: " . ($subscribeToWabaResponse->success ? 'true' : 'false'));

        if (!$subscribeToWabaResponse->success) {
            Log::error("[STEP 8] subscribeToWaba FAILED — Full error: " . json_encode($subscribeToWabaResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $subscribeToWabaResponse->data->error->message
            ]);
        }

        Log::info("[STEP 8] Overriding WABA callback URL to: {$callbackUrl}");
        $overridecallbackResponse = $this->overrideWabaCallbackUrl($accessToken, $wabaId, $callbackUrl, $token);
        Log::info("[STEP 8] overrideWabaCallbackUrl success: " . ($overridecallbackResponse->success ? 'true' : 'false'));

        if (!$overridecallbackResponse->success) {
            Log::error("[STEP 8] overrideWabaCallbackUrl FAILED — Full error: " . json_encode($overridecallbackResponse->data));
            return back()->with('status', [
                'type' => 'error',
                'message' => $overridecallbackResponse->data->error->message
            ]);
        }

        // Additional setup for coexistence mode
        if ($isCoexistence) {
            Log::info("[STEP 8-CO] Setting phone-level webhook for coexistence...");
            $overridePhoneCallbackResponse = $this->overridePhoneCallbackUrl($accessToken, $phoneNumberId, $callbackUrl, $token);
            Log::info("[STEP 8-CO] overridePhoneCallbackUrl success: " . ($overridePhoneCallbackResponse->success ? 'true' : 'false'));
            if (!$overridePhoneCallbackResponse->success) {
                Log::warning('[STEP 8-CO] overridePhoneCallbackUrl FAILED: ' . json_encode($overridePhoneCallbackResponse->data));
            }

            Log::info("[STEP 8-CO] Requesting history sync for WABA: {$wabaId}");
            $historySync = $this->requestHistorySync($accessToken, $wabaId);
            Log::info("[STEP 8-CO] requestHistorySync success: " . ($historySync->success ? 'true' : 'false'));
            if (!$historySync->success) {
                Log::warning('[STEP 8-CO] requestHistorySync FAILED: ' . json_encode($historySync->data));
            }

            Log::info("[STEP 8-CO] Syncing SMB contacts...");
            $this->syncSmbAppData($accessToken, $phoneNumberId, "smb_app_state_sync");

            Log::info("[STEP 8-CO] Syncing SMB message history...");
            $this->syncSmbAppData($accessToken, $phoneNumberId, "history");

            $this->verifyPhoneNumberConnection($accessToken, $phoneNumberId);
            $this->getWebhookSubscriptions($accessToken, $wabaId);
        }

        // 9) Persist metadata
        Log::info("[STEP 9] Saving metadata to organization...");
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['whatsapp']['is_embedded_signup'] = 1;
        $metadataArray['whatsapp']['is_coexistence'] = $isCoexistence ? 1 : 0;
        $metadataArray['whatsapp']['sync_contacts'] = 1;
        $metadataArray['whatsapp']['sync_history'] = 1;
        $metadataArray['whatsapp']['access_token'] = $accessToken;
        $metadataArray['whatsapp']['app_id'] = $tokenDetails['app_id'];
        $metadataArray['whatsapp']['waba_id'] = $wabaId;
        $metadataArray['whatsapp']['phone_number_id'] = $phoneNumberId;
        $metadataArray['whatsapp']['display_phone_number'] = $phoneNumberResponse->data->display_phone_number ?? null;
        $metadataArray['whatsapp']['verified_name'] = $phoneNumberResponse->data->verified_name ?? null;
        $metadataArray['whatsapp']['quality_rating'] = $phoneNumberResponse->data->quality_rating ?? null;
        $metadataArray['whatsapp']['name_status'] = $phoneNumberResponse->data->name_status ?? null;
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberResponse->data->messaging_limit_tier ?? null;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = null;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = null;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatusResponse->data->status ?? null;
        $metadataArray['whatsapp']['code_verification_status'] = $phoneNumberStatusResponse->data->code_verification_status ?? null;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatusResponse->data->account_review_status ?? null;
        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfileResponse->data->about ?? null;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfileResponse->data->address ?? null;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfileResponse->data->description ?? null;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfileResponse->data->vertical ?? null;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfileResponse->data->email ?? null;

        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();
        Log::info("[STEP 9] Metadata saved");

        // 10) Sync templates
        Log::info("[STEP 10] Syncing templates for WABA: {$wabaId}");
        $templateSync = $this->syncTemplates($accessToken, $wabaId);
        if (! $templateSync->success) {
            Log::error('[STEP 10] Template sync failed after access validation', [
                'waba_id' => $wabaId,
                'error' => $templateSync->data->error ?? null,
            ]);

            return back()->with('status', [
                'type' => 'error',
                'message' => __('WhatsApp was connected, but template synchronization failed: :message', [
                    'message' => $templateSync->data->error->message ?? __('Unknown Meta error'),
                ]),
            ]);
        }

        $totalTime = round((microtime(true) - $startTime) * 1000);
        Log::info("========== SIGNUP FLOW COMPLETE — total time: {$totalTime}ms ==========");

        return back()->with('status', [
            'type' => 'success',
            'message' => $isCoexistence
                ? __('Your WhatsApp number is connected via Coexistence!')
                : __('You\'ve successfully connected your account to WhatsApp!')
        ]);
    }

    // Request history sync for coexistence
    public function requestHistorySync($accessToken, $wabaId)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/history_sync", [
            'enable_history_sync' => true
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info('History sync request failed: ' . $response->body());
        }

        return $responseObject;
    }

    // Verify coexistence setup
    public function verifyCoexistenceSetup($accessToken, $wabaId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}", [
                'fields' => 'is_business_app_setup,message_template_namespace'
            ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::warning('Coexistence verification failed', [
                    'response' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Coexistence verification error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Verify phone number webhook configuration
    public function verifyPhoneNumberConnection($accessToken, $phoneNumberId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}", [
                'fields' => 'webhook_configuration,status'
            ]);

            if ($response->successful()) {
                Log::info('Phone number webhook config', [
                    'phone_id' => $phoneNumberId,
                    'config' => $response->json()
                ]);
                return $response->json();
            } else {
                Log::warning('Phone number verification failed', [
                    'phone_id' => $phoneNumberId,
                    'response' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Phone number verification error', [
                'phone_id' => $phoneNumberId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Get webhook subscriptions for debugging
    public function getWebhookSubscriptions($accessToken, $wabaId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

            if ($response->successful()) {
                Log::info('Current webhook subscriptions', [
                    'waba_id' => $wabaId,
                    'subscriptions' => $response->json()
                ]);
                return $response->json();
            } else {
                Log::warning('Failed to get webhook subscriptions', [
                    'waba_id' => $wabaId,
                    'response' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Webhook subscriptions error', [
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getAccessToken($token){
        Log::info("[getAccessToken] START");

        $settings = Setting::whereIn('key', ['whatsapp_client_id', 'whatsapp_client_secret'])->pluck('value', 'key');
        $clientId = $settings->get('whatsapp_client_id', null);
        $clientSecret = $settings->get('whatsapp_client_secret', null);

        Log::info("[getAccessToken] client_id: " . substr($clientId ?? 'NULL', 0, 8) . '...');
        Log::info("[getAccessToken] client_secret set: " . (empty($clientSecret) ? 'NO' : 'YES'));
        Log::info("[getAccessToken] code length: " . strlen($token ?? ''));

        $responseObject = new \stdClass();

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $token,
        ]);

        Log::info("[getAccessToken] HTTP status: " . $response->status());
        Log::info("[getAccessToken] Response received", [
            'http_status' => $response->status(),
            'successful' => $response->successful(),
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['error'])) {
                Log::error("[getAccessToken] Meta returned error in body: " . json_encode($response['error']));
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['error']['code'];
                $responseObject->data->error->message = $response['error']['message'];
            } else {
                Log::info("[getAccessToken] SUCCESS — token_type: " . ($response['token_type'] ?? 'N/A'));
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            Log::error("[getAccessToken] HTTP error — status: " . $response->status() . " body: " . $response->body());
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
        }

        return $responseObject;
    }

    public function debugToken($token){
        $settings = Setting::whereIn('key', ['whatsapp_client_id', 'whatsapp_client_secret', 'whatsapp_access_token'])->pluck('value', 'key');
        $diagnostics = new MetaTokenDiagnosticsService(
            $this->apiVersion,
            $settings->get('whatsapp_client_id'),
            $settings->get('whatsapp_client_secret'),
            $settings->get('whatsapp_access_token'),
        );
        $inspection = $diagnostics->inspectToken((string) $token);

        $responseObject = new \stdClass();
        $responseObject->success = $inspection['success'];
        $responseObject->classification = $inspection['classification'];
        $responseObject->data = (object) $inspection['details'];
        $responseObject->data->waba_id = $diagnostics->firstWhatsappManagementTarget($inspection);

        if (! $inspection['success']) {
            $responseObject->data->error = (object) [
                'message' => $inspection['message'],
            ];
        }

        return $responseObject;
    }

    private function discoverWabaIdFromBusinesses($accessToken)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/me", [
                'fields' => 'businesses{id}'
            ]);

            if (!$response->successful()) {
                Log::warning('[discoverWabaIdFromBusinesses] /me businesses failed: ' . $response->body());
                return null;
            }

            $businessData = $response->json();
            $businessId = $businessData['businesses']['data'][0]['id'] ?? ($businessData['id'] ?? null);

            if (empty($businessId)) {
                Log::warning('[discoverWabaIdFromBusinesses] No business ID found in /me response');
                return null;
            }

            $wabaResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$businessId}/owned_whatsapp_business_accounts");

            if (!$wabaResponse->successful()) {
                Log::warning('[discoverWabaIdFromBusinesses] owned_waba request failed: ' . $wabaResponse->body());
                return null;
            }

            $wabaData = $wabaResponse->json();
            return $wabaData['data'][0]['id'] ?? null;
        } catch (\Throwable $e) {
            Log::error('[discoverWabaIdFromBusinesses] Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function getPhoneNumberId($accessToken, $wabaId){
        Log::info("Getting phone number ID");

        $responseObject = new \stdClass();

        $fields = 'display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier';

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/phone_numbers", [
            'fields' => $fields,
            'access_token' => $accessToken,
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    public function getPhoneNumberStatus($accessToken, $phoneNumberId){
        Log::info("Getting phone number status");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}", [
            'fields' => 'status',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    public function getAccountReviewStatus($accessToken, $wabaId){
        Log::info("Getting account review status");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}", [
            'fields' => 'account_review_status',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    public function getBusinessProfile($accessToken, $phoneNumberId){
        Log::info("Getting business profile");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}/whatsapp_business_profile", [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    function subscribeToWaba($accessToken, $wabaId){
        Log::info("Subscribing to WABA");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function getWabaSubscriptions($accessToken, $wabaId){
        Log::info("Getting WABA subscriptions");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function overrideWabaCallbackUrl($accessToken, $wabaId, $callbackUrl, $verifyToken){
        Log::info("Overriding WABA callback URL");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps", [
            'override_callback_uri' => $callbackUrl,
            'verify_token' => $verifyToken
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    function overridePhoneCallbackUrl($accessToken, $phoneId, $callbackUrl, $verifyToken){
        Log::info("Overriding phone callback URL");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$phoneId}", [
            'webhook_configuration' => [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken
            ]
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    function unSubscribeToWaba($accessToken, $wabaId){
        Log::info("Unsubscribing from WABA");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->delete("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    function registerNumber($accessToken, $phoneNumberID){
        Log::info("Registering number");

        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/".$phoneNumberID."/register", [
            'messaging_product' => "whatsapp",
            'pin' => "123456",
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::error($response->body());
        }

        return $responseObject;
    }

    function syncTemplates($accessToken, $wabaId){
        Log::info("Syncing templates");

        $responseObject = new \stdClass();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/message_templates";

        try {
            do {
                $response = Http::acceptJson()
                    ->timeout(30)
                    ->withToken($accessToken)
                    ->get($url)
                    ->throw()
                    ->json();

                foreach(($response['data'] ?? []) as $templateData){
                    $template = Template::where('organization_id', session()->get('current_organization'))
                        ->where('meta_id', $templateData['id'])->first();

                    if($template){
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData['status'];
                        $template->updated_at = now();
                        $template->deleted_at = NULL;
                        $template->save();
                    } else {
                        $template = new Template();
                        $template->organization_id = session()->get('current_organization');
                        $template->meta_id = $templateData['id'];
                        $template->name = $templateData['name'];
                        $template->category = $templateData['category'];
                        $template->language = $templateData['language'];
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData['status'];
                        $template->created_by = auth()->user()->id;
                        $template->created_at = now();
                        $template->updated_at = now();
                        $template->save();
                    }
                };

                $url = $response['paging']['next'] ?? null;
            } while($url);

            $responseObject->success = true;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $graphError = $e->response->json('error') ?? [];
            $responseObject->data->error->code = $graphError['code'] ?? $e->response->status();
            $responseObject->data->error->error_subcode = $graphError['error_subcode'] ?? null;
            $responseObject->data->error->fbtrace_id = $graphError['fbtrace_id'] ?? null;
            $responseObject->data->error->message = $graphError['message'] ?? 'Meta rejected template synchronization.';
        } catch (\Throwable $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = (object) [
                'message' => $e->getMessage(),
            ];
        }

        return $responseObject;
    }

    public function getPhoneNumberById($accessToken, $phoneNumberId){
        Log::info("Getting phone number by ID");

        $responseObject = new \stdClass();

        $fields = 'display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}", [
                    'fields' => $fields,
                ]);

        if ($response->successful()) {
            $json = $response->json();
            if (isset($json['error'])) {
                $responseObject->success = false;
                $responseObject->data = (object) [
                    'error' => (object) [
                        'code' => $json['error']['code'] ?? null,
                        'message' => $json['error']['message'] ?? __('Unknown error'),
                    ]
                ];
            } else {
                $responseObject->success = true;
                $responseObject->data = (object) $json;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = (object) [
                'error' => (object) [
                    'message' => $response->body()
                ]
            ];
            Log::error($response->body());
        }

        return $responseObject;
    }

    private function diagnosticMessage(array $diagnostic): string
    {
        $classification = $diagnostic['classification'] ?? 'META_ACCESS_DIAGNOSIS_FAILED';
        $message = $diagnostic['message'] ?? 'Meta access validation failed.';
        $graphError = $this->firstGraphError($diagnostic['details'] ?? []);

        if ($graphError !== null) {
            $code = $graphError['code'] ?? $graphError['http_status'] ?? null;
            $trace = $graphError['fbtrace_id'] ?? null;
            $metaMessage = $graphError['message'] ?? null;

            if ($code !== null || $metaMessage !== null) {
                $message .= ' Meta: '.($code !== null ? "(#{$code}) " : '').($metaMessage ?? 'Unknown error').'.';
            }

            if ($trace !== null) {
                $message .= " Trace ID: {$trace}.";
            }
        }

        return "[{$classification}] {$message}";
    }

    private function firstGraphError(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['graph_error']) && is_array($value['graph_error'])) {
            return $value['graph_error'];
        }

        foreach ($value as $nested) {
            $error = $this->firstGraphError($nested);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Generic sync for WhatsApp SMB app data
     *
     * @param string $accessToken
     * @param string $phoneNumberId
     * @param string $type - "smb_app_state_sync" | "history"
     */
    private function syncSmbAppData($accessToken, $phoneNumberId, $type){
        
        Log::info("Syncing SMB app data [{$type}] for Phone ID: {$phoneNumberId}");

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}/smb_app_data";
        $params = [
            "messaging_product" => "whatsapp",
            "sync_type" => $type
        ];

        Log::info("Initiating SMB sync [{$type}] for Phone ID: {$phoneNumberId}");
        Log::info("URL: {$url}");
        Log::info("Params: " . json_encode($params));

        $response = Http::withHeaders([
            'Authorization' => "Bearer " . $accessToken,
            'Content-Type' => 'application/json'
        ])->post($url, $params);

        Log::info("SMB sync [{$type}] response status: " . $response->status());
        Log::info("SMB sync [{$type}] response body: " . $response->body());

        if ($response->successful()) {
            return $response->json();
        } else {
            Log::error("Failed to initiate SMB sync [{$type}]. Status: " . $response->status() . " Body: " . $response->body());
            return null;
        }
    }

}
