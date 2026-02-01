<?php

namespace App\Http\Controllers\User;

use Illuminate\Support\Facades\DB;
use Validator;
use Inertia\Inertia;
use App\Models\Addon;
use App\Models\Label;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Template;
use App\Models\ContactGroup;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Helpers\CustomHelper;
use App\Services\WhatsappService;
use App\Models\ContactContactGroup;
use Illuminate\Support\Facades\URL;
use App\Services\ContactFieldService;
use App\Http\Requests\StoreWhatsappProfile;
use App\Http\Requests\StoreWhatsappSettings;
use App\Http\Controllers\Controller as BaseController;

class SettingController extends BaseController
{
    protected $contactFieldService;
    public function __construct(ContactFieldService $contactFieldService)
    {
        $this->contactFieldService = $contactFieldService;
    }

    public function index(Request $request, $display = null){
        if ($request->isMethod('get')) {
            $organizationId = session()->get('current_organization');
            $data['title'] = __('Settings');
            $data['settings'] = Organization::where('id', $organizationId)->first();
            $data['timezones'] = config('formats.timezones');
            $data['countries'] = config('formats.countries');
            $data['sounds'] = config('sounds');
            $data['modules'] = Addon::get();
            $contactModel = new Contact;
            $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);

           /*  $metadata = $data['settings']->metadata 
            ? json_decode($data['settings']->metadata, true) 
            : [];
        if (!isset($metadata['campaign_settings'])) {
            $metadata['campaign_settings'] = [];
        }

        if (!isset($metadata['campaign_settings']['unsubscribe_trigger'])) {
            $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop';
        }
        $data['settings']->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $data['settings']->save();*/

            return Inertia::render('User/Settings/General', $data);
        }
    }

    public function mobileView(Request $request){
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', session()->get('current_organization'))->first();
        return Inertia::render('User/Settings/Main', $data);
    }

    public function viewGeneralSettings(Request $request){
        $contactModel = new Contact;
        $organizationId = session()->get('current_organization');
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', session()->get('current_organization'))->first();
        $data['modules'] = Addon::get();
        $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);
        
        return Inertia::render('User/Settings/General', $data);
    }

    public function viewWhatsappSettings(Request $request){
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');

        $data = [
            'embeddedSignupActive' => CustomHelper::isModuleEnabled('Embedded Signup'),
            'graphAPIVersion' => config('graph.api_version'),
            'appId' => $settings->get('whatsapp_client_id', null),
            'configId' => $settings->get('whatsapp_config_id', null),
            'settings' => Organization::where('id', session()->get('current_organization'))->first(),
            'modules' => Addon::get(),
            'title' => __('Settings'),
        ];

        return Inertia::render('User/Settings/Whatsapp', $data);
    }

    public function storeWhatsappSettings(StoreWhatsappSettings $request) {
        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $setWebhookUrl = $embeddedSignupActive == 1 ? true : false;

        return $this->saveWhatsappSettings(
            $request->access_token,
            $request->app_id,
            $request->phone_number_id,
            $request->waba_id,
            $setWebhookUrl
        );
    }

    public function updateToken(Request $request) {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }
        
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];

        return $this->saveWhatsappSettings(
            $request->access_token,
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null
        );
    }
    
    public function refreshWhatsappData() {
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];

        if($config['whatsapp']['is_embedded_signup'] && $config['whatsapp']['is_embedded_signup'] == 1){
            if (class_exists(\Modules\EmbeddedSignup\Services\MetaService::class)) {
                $embeddedSetup = new \Modules\EmbeddedSignup\Services\MetaService();
                $embeddedSetup->overrideWabaCallbackUrl($organizationId);
            }
        }
    
        return $this->saveWhatsappSettings(
            $config['whatsapp']['access_token'] ?? null,
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null
        );
    }
  
public function campaign(Request $request)
{
    $currentOrganizationId = session()->get('current_organization');
    $organizationConfig = Organization::findOrFail($currentOrganizationId);

    // Decode metadata or set as empty array
    $metadataArray = $organizationConfig->metadata
        ? json_decode($organizationConfig->metadata, true)
        : [];

    // Ensure campaign_settings key exists
    if (!isset($metadataArray['campaign_settings'])) {
        $metadataArray['campaign_settings'] = [];
    }

    // Handle POST request (save settings)
    if ($request->isMethod('post')) {
        $input = $request->input('unsubscribe_trigger', 'stop');

        // Normalize words (remove spaces around commas)
        $words = preg_replace('/\s*,\s*/', ',', trim($input));
        $metadataArray['campaign_settings']['unsubscribe_trigger'] = $words;

        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();

        return back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Campaign settings updated successfully'),
            ]
        );
    }

    // Ensure unsubscribe_trigger has a default value
    if (!isset($metadataArray['campaign_settings']['unsubscribe_trigger'])) {
        $metadataArray['campaign_settings']['unsubscribe_trigger'] = 'stop';
    }

    $organizationConfig->metadata = json_encode($metadataArray);

    return Inertia::render('User/Settings/Campaign', [
        'settings' => $organizationConfig,
        'modules'  => Addon::get(), 
    ]);
}
    public function tickets(Request $request){
        //dd($request);
        if ($request->isMethod('get')) {
            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $settings = Organization::where('id', session()->get('current_organization'))->first();
            
            $agents = DB::table('users')
                ->join('teams', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', '=', session()->get('current_organization'))
                ->where('teams.role', '=', 'agent')
                ->whereNull('teams.deleted_at')
                ->select('users.*')
                ->get();

            $oldSelectedAgents = DB::table('assigned_agents')
                ->where('organization_id', session()->get('current_organization'))
                ->pluck('user_id')
                ->toArray();
//dd($agents, $oldSelectedAgents);
            return Inertia::render('User/Settings/Ticket', [
                'title' => __('Settings'),
                'filters' => $request->all(),
                'rows' => $contactFieldService->get($request),
                'settings' => $settings,
                'modules' => Addon::get(),
                'agents' => $agents,
                'oldSelectedAgents' => $oldSelectedAgents
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['tickets']['active'] = $request->active;
            $metadataArray['tickets']['auto_assignment'] = $request->auto_assignment ?? false;
            $metadataArray['tickets']['assigned_to_specific_agents'] = $request->auto_robinhood ?? false;
            $metadataArray['tickets']['reassign_reopened_chats'] = $request->reassign_reopened_chats;
            $metadataArray['tickets']['allow_agents_to_view_all_chats'] = $request->allow_agents_to_view_all_chats;

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            if (is_array($request->selected_agents)) {
                $this->syncAssignedAgents($currentOrganizationId, $request->selected_agents);
            }
            /*return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );*/
        }
    }

    public function automation(Request $request){
        if ($request->isMethod('get')) {
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            return Inertia::render('User/Settings/Automation', [
                'title' => __('Settings'),
                'settings' => $settings,
                'modules' => Addon::get(),
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
            $metadataArray['automation']['response_sequence'] = $request->response_sequence;

            $updatedMetadataJson = json_encode($metadataArray);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            /*return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );*/
        }
    }

    public function whatsappBusinessProfileUpdate(StoreWhatsappProfile $request){
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $organizationId = session()->get('current_organization');
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        if(isset($config['whatsapp'])){
            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = config('graph.api_version');
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
            
            $response = $whatsappService->updateBusinessProfile($request);

            if($response->success === true){
                return back()->with(
                    'status', [
                        'type' => 'success', 
                        'message' => __('Your whatsapp business profile has been changed successfully!')
                    ]
                );
            } else {
                return back()->with(
                    'status', [
                        'type' => 'error', 
                        'message' => __('Something went wrong! Your business profile could not be updated!')
                    ]
                );
            }
        }

        return back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Setup your whatsapp integration first!')
            ]
        );
    }

    public function deleteWhatsappIntegration(Request $request){
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $config = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if(isset($config['whatsapp'])){
            if($embeddedSignupActive == 1){
                //Unsubscribe webhook
                $organizationId = session()->get('current_organization');
                $apiVersion = config('graph.api_version');

                $accessToken = $config['whatsapp']['access_token'] ?? null;
                $appId = $config['whatsapp']['app_id'] ?? null;
                $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
                $wabaId = $config['whatsapp']['waba_id'] ?? null;
            
                $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
                $unsubscribe = $whatsappService->unSubscribeToWaba();
            }
            
            //Delete whatsapp settings
            if (isset($config['whatsapp'])) {
                unset($config['whatsapp']);
            }

            $updatedMetadataJson = json_encode($config);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            //Delete templates
            $templates = Template::where('organization_id', $organizationId)->get();
            foreach ($templates as $template) {
                $template->deleted_at = now();
                $template->save();
            }

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Your integration has been removed successfully!')
                ]
            );
        }

        return back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Setup your whatsapp integration first!')
            ]
        );
    }

    private function saveWhatsappSettings($accessToken, $appId, $phoneNumberId, $wabaId, $subscribeToWebhook = false) {
        $organizationId = session()->get('current_organization');
        $apiVersion = config('graph.api_version');
    
        $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);

        $phoneNumberResponse = $whatsappService->getPhoneNumberId($accessToken, $wabaId);
     
        if(!$phoneNumberResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberResponse->data->error->message
                ]
            );
        }

        //Get Phone Number Status
        $phoneNumberStatusResponse = $whatsappService->getPhoneNumberStatus($accessToken, $phoneNumberResponse->data->id); 
        
        if(!$phoneNumberStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberStatusResponse->data->error->message
                ]
            );
        }

        //Get Account Review Status
        $accountReviewStatusResponse = $whatsappService->getAccountReviewStatus($accessToken, $wabaId);
        
        if(!$accountReviewStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $accountReviewStatusResponse->data->error->message
                ]
            );
        }

        //Get business profile
        $businessProfileResponse = $whatsappService->getBusinessProfile($accessToken, $phoneNumberResponse->data->id);  
        
        if(!$businessProfileResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $businessProfileResponse->data->error->message
                ]
            );
        }
        $organizationConfig = Organization::where('id', $organizationId)->first();
        
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        
        if (!isset($metadataArray['whatsapp']) || !is_array($metadataArray['whatsapp'])) {
            $metadataArray['whatsapp'] = [];
        }

        $metadataArray['whatsapp']['is_embedded_signup'] = $metadataArray['whatsapp']['is_embedded_signup'] ?? 0;
        $metadataArray['whatsapp']['access_token'] = $accessToken;
        $metadataArray['whatsapp']['app_id'] = $appId;
        $metadataArray['whatsapp']['waba_id'] = $wabaId;
        $metadataArray['whatsapp']['phone_number_id'] = $phoneNumberResponse->data->id;
        $metadataArray['whatsapp']['display_phone_number'] = $phoneNumberResponse->data->display_phone_number;
        $metadataArray['whatsapp']['verified_name'] = $phoneNumberResponse->data->verified_name ?? null;
        $metadataArray['whatsapp']['quality_rating'] = $phoneNumberResponse->data->quality_rating ?? null;
        $metadataArray['whatsapp']['name_status'] = $phoneNumberResponse->data->name_status ?? null;
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberResponse->data->messaging_limit_tier ?? NULL;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = NULL;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = NULL;
        $metadataArray['whatsapp']['whatsapp_business_manager_messaging_limit'] = $phoneNumberStatusResponse->data->whatsapp_business_manager_messaging_limit ?? NULL;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatusResponse->data->status;
        $metadataArray['whatsapp']['code_verification_status'] = $phoneNumberStatusResponse->data->code_verification_status;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatusResponse->data->account_review_status;

        if (!isset($metadataArray['whatsapp']['business_profile']) || !is_array($metadataArray['whatsapp']['business_profile'])) {
            $metadataArray['whatsapp']['business_profile'] = [];
        }

        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfileResponse->data->about ?? NULL;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfileResponse->data->address ?? NULL;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfileResponse->data->description ?? NULL;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfileResponse->data->vertical ?? NULL;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfileResponse->data->email ?? NULL;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if($organizationConfig->save()){
            $whatsappService->syncTemplates($accessToken, $wabaId);

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Whatsapp settings updated successfully')
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    protected function abortIfDemo(){
        $organizationId = session()->get('current_organization');

        if (app()->environment('demo') && $organizationId == 1) {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('You cannot perform this action using the demo account. To test this feature, please create your own account.')
                ]
            );
        }

        return null;
    }

   public function contacts(Request $request){
        if ($request->isMethod('get')) {
            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            return Inertia::render('User/Settings/Contact', [
                'title' => __('Settings'),
                'filters' => $request->all(),
                'rows' => $contactFieldService->get($request),
                'settings' => $settings,
                'modules' => Addon::get(),
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['contacts']['location'] = $request->location;

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
           
            $organizationConfig->save();

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );
        }
    }

    public function updateWhatsappForCoExistence(Request $request){
        
        $request->validate([
            'sync_history'  => 'nullable|in:0,1',
            'sync_contacts' => 'nullable|in:0,1',
        ]);

        // Ensure at least one field exists
        if (!$request->has('sync_history') && !$request->has('sync_contacts')) {
            return back()->withErrors([
                'sync_settings' => 'At least one of sync_history or sync_contacts is required.'
            ])->withInput();
        }


        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::findOrFail($organizationId);

        // decode metadata JSON or start with empty array
        $metadataArray = $organizationConfig->metadata
            ? json_decode($organizationConfig->metadata, true)
            : [];

        // Update only provided fields
        if ($request->has('sync_history')) {
            $metadataArray['sync_history'] = (int) $request->input('sync_history');
        }

        if ($request->has('sync_contacts')) {
            $metadataArray['sync_contacts'] = (int) $request->input('sync_contacts');
        }

        // Save updated metadata
        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();

        return back()->with([
            'type'    => 'success',
            'message' => 'Sync settings updated successfully.',
        ]);
        
    }

    public function viewGlobalReplySettings(Request $request){
        $organizationId = session()->get('current_organization');
        $settings = Organization::where('id', $organizationId)->first();

        // Compose defaults for frontend without touching DB
        $metadata = $settings->metadata ? json_decode($settings->metadata, true) : [];
        if (!isset($metadata['global_reply'])) {
            $metadata['global_reply'] = [
                'active'  => 0,
                'message' => '',
                'buttons' => [],
            ];
        }

        if (!isset($metadata['working_hour_reply'])) {
            $metadata['working_hour_reply'] = [
                'active'  => 0,
                'message' => '',
                'buttons' => [],
            ];
        }

        $settings->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        return Inertia::render('User/Settings/GlobalReply', [
            'title'    => __('Settings'),
            'settings' => $settings,
            'modules'  => Addon::get(),
        ]);
    }

    public function greetingMessageSettings(){
        $organizationId = session()->get('current_organization');
        $settings = Organization::where('id', $organizationId)->first();

        // Compose defaults for frontend without touching DB
        $metadata = $settings->metadata ? json_decode($settings->metadata, true) : [];
        if (!isset($metadata['greeting_message'])) {
            $metadata['greeting_message'] = [
                'active'  => 0,
                'message' => '',
                'buttons' => [],
            ];
        }


        $settings->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        return Inertia::render('User/Settings/GreetingMessage', [
            'title'    => __('Settings'),
            'settings' => $settings,
            'modules'  => Addon::get(),
        ]);
    }


    public function updateGlobalReply(Request $request){
        // Validation
        $rules = [
            'active'                 => ['required', 'boolean'],
            'message'                => ['required_if:active,1', 'nullable', 'string', 'max:1000'],
            'buttons'                => ['required_if:active,1', 'array', 'min:1', 'max:3'],
            'buttons.*.title'        => ['required', 'string', 'max:20'],
            //  'buttons.*.payload'      => ['required', 'string', 'max:256'],
        ];

        $messages = [
            'buttons.required_if' => __('At least one button is required when global reply is active.'),
            'buttons.min'         => __('Add at least one button.'),
            'buttons.max'         => __('Maximum 3 buttons allowed.'),
        ];

        $this->validate($request, $rules, $messages);

        $organizationId   = session()->get('current_organization');
        $organization     = Organization::where('id', $organizationId)->firstOrFail();
        $metadataArray    = $organization->metadata ? json_decode($organization->metadata, true) : [];

        // Sanitize & clamp to 3 buttons
        $buttons = collect($request->input('buttons', []))
            ->take(3)
            ->map(function ($btn) {
                return [
                    'title'   => trim(mb_substr((string)($btn['title'] ?? ''), 0, 20)),
                    'payload' => trim(mb_substr((string)($btn['title'] ?? ''), 0, 256)),
                ];
            })
            ->values()
            ->all();

        $metadataArray['global_reply'] = [
            'active'  => $request->boolean('active') ? 1 : 0,
            'message' => trim((string)$request->input('message', '')),
            'buttons' => $buttons,
        ];

        $organization->metadata = json_encode($metadataArray, JSON_UNESCAPED_UNICODE);
        $organization->save();

        return back()->with(
            'status',
            [
                'type'    => 'success',
                'message' => __('Global reply settings updated successfully.'),
            ]
        );
    }

        public function updateAfterHoursReply(Request $request){
        // Validation
        $rules = [
            'active'                 => ['required', 'boolean'],
            'message'                => ['required_if:active,1', 'nullable', 'string', 'max:1000'],
            'buttons'                => ['required_if:active,1', 'array', 'min:1', 'max:3'],
            'buttons.*.title'        => ['required', 'string', 'max:20'],
            //  'buttons.*.payload'      => ['required', 'string', 'max:256'],
        ];

        $messages = [
            'buttons.required_if' => __('At least one button is required when global reply is active.'),
            'buttons.min'         => __('Add at least one button.'),
            'buttons.max'         => __('Maximum 3 buttons allowed.'),
        ];

        $this->validate($request, $rules, $messages);

        $organizationId   = session()->get('current_organization');
        $organization     = Organization::where('id', $organizationId)->firstOrFail();
        $metadataArray    = $organization->metadata ? json_decode($organization->metadata, true) : [];

        // Sanitize & clamp to 3 buttons
        $buttons = collect($request->input('buttons', []))
            ->take(3)
            ->map(function ($btn) {
                return [
                    'title'   => trim(mb_substr((string)($btn['title'] ?? ''), 0, 20)),
                    'payload' => trim(mb_substr((string)($btn['title'] ?? ''), 0, 256)),
                ];
            })
            ->values()
            ->all();

        $metadataArray['working_hour_reply'] = [
            'active'  => $request->boolean('active') ? 1 : 0,
            'message' => trim((string)$request->input('message', '')),
            'buttons' => $buttons,
        ];

        $organization->metadata = json_encode($metadataArray, JSON_UNESCAPED_UNICODE);
        $organization->save();

        return back()->with(
            'status',
            [
                'type'    => 'success',
                'message' => __('Working Hour reply settings updated successfully.'),
            ]
        );
    }

        public function updateGreetingMessageSettings(Request $request){
        // Validation
        $rules = [
            'active'                 => ['required', 'boolean'],
            'message'                => ['required_if:active,1', 'nullable', 'string', 'max:1000'],
            'buttons'                => ['required_if:active,1', 'array', 'min:1', 'max:3'],
            'buttons.*.title'        => ['required', 'string', 'max:20'],
            //  'buttons.*.payload'      => ['required', 'string', 'max:256'],
        ];

        $messages = [
            'buttons.required_if' => __('At least one button is required when global reply is active.'),
            'buttons.min'         => __('Add at least one button.'),
            'buttons.max'         => __('Maximum 3 buttons allowed.'),
        ];

        $this->validate($request, $rules, $messages);

        $organizationId   = session()->get('current_organization');
        $organization     = Organization::where('id', $organizationId)->firstOrFail();
        $metadataArray    = $organization->metadata ? json_decode($organization->metadata, true) : [];

        // Sanitize & clamp to 3 buttons
        $buttons = collect($request->input('buttons', []))
            ->take(3)
            ->map(function ($btn) {
                return [
                    'title'   => trim(mb_substr((string)($btn['title'] ?? ''), 0, 20)),
                    'payload' => trim(mb_substr((string)($btn['title'] ?? ''), 0, 256)),
                ];
            })
            ->values()
            ->all();

        $metadataArray['greeting_message'] = [
            'active'  => $request->boolean('active') ? 1 : 0,
            'message' => trim((string)$request->input('message', '')),
            'buttons' => $buttons,
        ];

        $organization->metadata = json_encode($metadataArray, JSON_UNESCAPED_UNICODE);
        $organization->save();

        return back()->with(
            'status',
            [
                'type'    => 'success',
                'message' => __('Greeting message settings updated successfully.'),
            ]
        );
    }


    public function labels(Request $request){
        $organizationId = session()->get('current_organization');
        $organization = Organization::findOrFail($organizationId);

        if ($request->isMethod('get')) {
            $labels = $organization->labels()->latest()->get();
            
            // Add contact count for each label
            $labels->each(function($label) use ($organizationId) {
                $label->contact_count = Contact::where('organization_id', $organizationId)
                    ->whereHas('labels', function($query) use ($label) {
                        $query->where('labels.id', $label->id);
                    })
                    ->count();
            });

            return Inertia::render('User/Settings/Label', [
                'title'        => __('Settings'),
                'settings'     => $organization,
                'labels'       => $labels,
                'modules'      => Addon::get(),
            ]);
        }

        if ($request->isMethod('post')) {
            $request->validate([
                'title' => 'required|string|max:255',
                'color' => 'required|string|max:7',
            ]);

            $organization->labels()->create([
                'title' => $request->title,
                'color' => $request->color,
            ]);

            return back()->with(
                'status', [
                    'type'    => 'success',
                    'message' => __('Label created successfully'),
                ]
            );
        }
    }

    public function updateLabel(Request $request, Label $label){
        $request->validate([
            'title' => 'required|string|max:255',
            'color' => 'required|string|max:7',
        ]);

        $label->update($request->only('title', 'color'));

        return back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Label updated successfully'),
            ]
        );
    }

    public function deleteLabel(Label $label){
        $label->delete();

        return back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Label deleted successfully'),
            ]
        );
    }

    public function createGroupFromLabel(Request $request){
        $request->validate([
            'name' => 'required|string|max:255',
            'label_id' => 'required|exists:labels,id',
        ]);

        $organizationId = session()->get('current_organization');

        $label = Label::where('id', $request->label_id)
        ->where('organization_id', $organizationId)
        ->firstOrFail();

        $contactGroup = ContactGroup::create([
            'name' => $request->name,
            'organization_id' => $organizationId,
            'created_by' => auth()->user()->id,
        ]);

        // Get all contacts that have this label
        $contactsWithLabel = Contact::where('organization_id', $organizationId)
        ->whereHas('labels', function($query) use ($request) {
            $query->where('labels.id', $request->label_id);
        })
        ->get();

        // Add these contacts to the new group
        foreach ($contactsWithLabel as $contact) {
            ContactContactGroup::create([
                'contact_id' => $contact->id,
                'contact_group_id' => $contactGroup->id,
            ]);
        }

        return back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Contact group created successfully with :count contacts', ['count' => $contactsWithLabel->count()])
            ]);
    }

    private function syncAssignedAgents($organizationId, array $selectedAgents)
    {
        DB::table('assigned_agents')
            ->where('organization_id', $organizationId)
            ->delete();

        $data = [];
        $now = now();

        foreach ($selectedAgents as $userId) {
            $data[] = [
                'organization_id' => $organizationId,
                'user_id'        => $userId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if (!empty($data)) {
            DB::table('assigned_agents')->insert($data);
        }
    }
}