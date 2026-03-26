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

class RegisterController extends BaseController
{
    private $apiVersion;

    public function __construct(){
        $this->apiVersion = config('graph.api_version');
    }

    public function handleSignup(Request $request){
        
        Log::info("Starting signup flow");

        $organizationId = session()->get('current_organization');

        Log::info("Organization ID: {$organizationId}");


        // NEW: read coexistence + optionally exact ids from the UI
        $isCoexistence = (bool) $request->boolean('coexistence');
        $phoneNumberIdFromUI = $request->input('phone_number_id'); // may be null
        $wabaIdFromUI = $request->input('waba_id'); // may be null

        Log::info("Coexistence: {$isCoexistence}");
        Log::info("Phone number ID from UI: {$phoneNumberIdFromUI}");
        Log::info("WABA ID from UI: {$wabaIdFromUI}");


        // 1) Exchange code for short-lived access token
        $accessTokenResponse = $this->getAccessToken($request->token);
        if (!$accessTokenResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $accessTokenResponse->data->error->message
            ]);
        }

        $accessToken = $accessTokenResponse->data->access_token;

        // 2) Debug token -> app_id + waba_id (granular_scopes)
        $debugTokenResponse = $this->debugToken($accessToken);
        if (!$debugTokenResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $debugTokenResponse->data->error->message
            ]);
        }

        // Prefer the WABA id passed by the UI (what user selected), else from debug_token
        $wabaId = $wabaIdFromUI ?: $debugTokenResponse->data->waba_id;

        if (empty($wabaId)) {
            return back()->with('status', [
                'type' => 'error',
                'message' => __('Unable to determine WABA ID from signup flow.')
            ]);
        }

        // 3) Fetch the chosen phone number (if UI gave us one), else fallback to first phone on the WABA (current behavior)
        if (!empty($phoneNumberIdFromUI)) {
            $phoneNumberResponse = $this->getPhoneNumberById($accessToken, $phoneNumberIdFromUI);
        } else {
            $phoneNumberResponse = $this->getPhoneNumberId($accessToken, $wabaId);
        }

        if (!$phoneNumberResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $phoneNumberResponse->data->error->message
            ]);
        }

        $phoneNumberId = $phoneNumberResponse->data->id;

        // 4) Status for the phone number
        $phoneNumberStatusResponse = $this->getPhoneNumberStatus($accessToken, $phoneNumberId);
        if (!$phoneNumberStatusResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $phoneNumberStatusResponse->data->error->message
            ]);
        }

        // 5) Account review status
        $accountReviewStatusResponse = $this->getAccountReviewStatus($accessToken, $wabaId);
        if (!$accountReviewStatusResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $accountReviewStatusResponse->data->error->message
            ]);
        }

        // 6) Register number (ONLY for non-coexistence; ESU + Coex already link/register the number)
        // Note: #131000 can occur if the number is already registered via the embedded signup OAuth flow
        // or if the user has a personal WhatsApp 2FA PIN. We treat this as non-fatal and continue.
        if (!$isCoexistence) {
            $registerNumber = $this->registerNumber($accessToken, $phoneNumberId);
            if (!$registerNumber->success) {
                $errorBody = $registerNumber->data->error->message ?? '';
                $errorData = json_decode($errorBody, true);
                $errorCode = $errorData['error']['code'] ?? null;

                // #131000 = number already registered or 2FA PIN mismatch — safe to continue
                if ($errorCode !== 131000) {
                    return back()->with('status', [
                        'type' => 'error',
                        'message' => $registerNumber->data->error->message
                    ]);
                }

                Log::warning("registerNumber returned #131000 — number may already be registered via embedded signup. Continuing flow.", [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $errorBody,
                ]);
            }
        }

        // 7) Business profile
        $businessProfileResponse = $this->getBusinessProfile($accessToken, $phoneNumberId);
        if (!$businessProfileResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $businessProfileResponse->data->error->message
            ]);
        }

        // 8) Subscribe app + set webhook
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $callbackUrl = URL::to('/') . '/webhook/whatsapp/' . $organizationConfig->identifier;
        $token = $organizationConfig->identifier;

        $subscribeToWabaResponse = $this->subscribeToWaba($accessToken, $wabaId);
        if (!$subscribeToWabaResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $subscribeToWabaResponse->data->error->message
            ]);
        }

        $overridecallbackResponse = $this->overrideWabaCallbackUrl($accessToken, $wabaId, $callbackUrl, $token);
        if (!$overridecallbackResponse->success) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $overridecallbackResponse->data->error->message
            ]);
        }

        // NEW: Additional setup for coexistence mode
        if ($isCoexistence) {
            // Set phone-level webhook for coexistence
            $overridePhoneCallbackResponse = $this->overridePhoneCallbackUrl($accessToken, $phoneNumberId, $callbackUrl, $token);
            if (!$overridePhoneCallbackResponse->success) {
                Log::warning('Failed to set phone-level webhook for coexistence', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $overridePhoneCallbackResponse->data->error->message ?? 'Unknown error'
                ]);
            }

            // Request history sync for coexistence (optional, may fail if not enabled)
            $historySync = $this->requestHistorySync($accessToken, $wabaId);
            if (!$historySync->success) {
                Log::info('History sync request failed (may not be enabled)', [
                    'waba_id' => $wabaId,
                    'error' => $historySync->data->error->message ?? 'Unknown error'
                ]);
            }

            // 🔹 Sync contacts
            $this->syncSmbAppData($accessToken, $phoneNumberId, "smb_app_state_sync");

            // 🔹 Sync message history
            $this->syncSmbAppData($accessToken, $phoneNumberId, "history");

            // Verify webhook configurations after setup
            $this->verifyPhoneNumberConnection($accessToken, $phoneNumberId);
            $this->getWebhookSubscriptions($accessToken, $wabaId);
        }

        // 9) Persist metadata (add is_coexistence without touching existing flags)
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['whatsapp']['is_embedded_signup'] = 1; // keep your existing marker
        $metadataArray['whatsapp']['is_coexistence'] = $isCoexistence ? 1 : 0; // NEW
        $metadataArray['whatsapp']['sync_contacts'] = 1;
        $metadataArray['whatsapp']['sync_history'] = 1;
        $metadataArray['whatsapp']['access_token'] = $accessToken;
        $metadataArray['whatsapp']['app_id'] = $debugTokenResponse->data->app_id;
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

        // 10) Sync templates (same for both)
        $this->syncTemplates($accessToken, $wabaId);

        Log::info("\n----------End signup flow--------\n");
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
        Log::info("Getting access token");

        $settings = Setting::whereIn('key', ['whatsapp_client_id', 'whatsapp_client_secret'])->pluck('value', 'key');
        $clientId = $settings->get('whatsapp_client_id', null);
        $clientSecret = $settings->get('whatsapp_client_secret', null);

        $responseObject = new \stdClass();

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $token,
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['error']['code'];
                $responseObject->data->error->message = $response['error']['message'];
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

    public function debugToken($token){
        Log::info("Getting debug token");

        $accessToken = Setting::where('key', 'whatsapp_access_token')->value('value');
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/debug_token", [
            'input_token' => $token
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
                $appId = null;
                $firstWabaId = null;
    
                $appId = $response['data']['app_id'] ?? null;
    
                // Check if the response data is structured as expected
                if (isset($response['data']['granular_scopes'])) {
                    foreach ($response['data']['granular_scopes'] as $scope) {
                        if ($scope['scope'] === 'whatsapp_business_management' && isset($scope['target_ids'][0])) {
                            $firstWabaId = $scope['target_ids'][0];
                            break;
                        }
                    }
                }
    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data->app_id = $appId;
                $responseObject->data->waba_id = $firstWabaId;
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

        try {
            do {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken
                ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/message_templates")->throw()->json();

                foreach($response['data'] as $templateData){
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

                if(isset($response['paging']) && isset($response['paging']['next'])) {
                    $url = $response['paging']['next'];
                } else {
                    $url = null; // Break the loop if no next page URL is available
                }
            } while($url);

            $responseObject->success = true;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
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