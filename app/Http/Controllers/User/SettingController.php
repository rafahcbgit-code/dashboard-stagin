<?php

namespace App\Http\Controllers\User;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWhatsappProfile;
use App\Http\Requests\StoreWhatsappSettings;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\ContactContactGroup;
use App\Models\ContactGroup;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\ContactFieldService;
use App\Services\ContactGroupCountService;
use App\Services\WhatsappService;
use App\Support\ContactPageCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SettingController extends BaseController
{
    protected $contactFieldService;

    public function __construct(ContactFieldService $contactFieldService)
    {
        $this->contactFieldService = $contactFieldService;
    }

    public function index(Request $request, $display = null)
    {
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

    public function mobileView(Request $request)
    {
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', session()->get('current_organization'))->first();

        return Inertia::render('User/Settings/Main', $data);
    }

    public function viewGeneralSettings(Request $request)
    {
        $contactModel = new Contact;
        $organizationId = session()->get('current_organization');
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', session()->get('current_organization'))->first();
        $data['modules'] = Addon::get();
        $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);

        return Inertia::render('User/Settings/General', $data);
    }

    public function viewWhatsappSettings(Request $request)
    {
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

    public function storeWhatsappSettings(StoreWhatsappSettings $request)
    {
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

    public function updateToken(Request $request)
    {
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

    public function refreshWhatsappData()
    {
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];

        if ($config['whatsapp']['is_embedded_signup'] && $config['whatsapp']['is_embedded_signup'] == 1) {
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
        if (! isset($metadataArray['campaign_settings'])) {
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
        if (! isset($metadataArray['campaign_settings']['unsubscribe_trigger'])) {
            $metadataArray['campaign_settings']['unsubscribe_trigger'] = 'stop';
        }

        $organizationConfig->metadata = json_encode($metadataArray);

        return Inertia::render('User/Settings/Campaign', [
            'settings' => $organizationConfig,
            'modules' => Addon::get(),
        ]);
    }

    public function tickets(Request $request)
    {
        //dd($request);
        if ($request->isMethod('get')) {
            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            $agents = DB::table('users')
                ->join('teams', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', '=', session()->get('current_organization'))
                ->whereNull('teams.deleted_at')
                ->select('users.id', 'users.first_name', 'users.last_name', 'users.email')
                ->distinct()
                ->get();

            $oldSelectedAgents = DB::table('assigned_agents')
                ->where('organization_id', session()->get('current_organization'))
                ->pluck('user_id')
                ->toArray();

            return Inertia::render('User/Settings/Ticket', [
                'title' => __('Settings'),
                'filters' => $request->all(),
                'rows' => $contactFieldService->get($request),
                'settings' => $settings,
                'modules' => Addon::get(),
                'agents' => $agents,
                'oldSelectedAgents' => $oldSelectedAgents,
            ]);
        } elseif ($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
            $metadataArray['tickets'] = $metadataArray['tickets'] ?? [];
            $noteEmailRecipientScope = $request->input('note_email_notifications_recipient_scope', 'assigned_agent');

            if (! in_array($noteEmailRecipientScope, ['assigned_agent', 'all_team'], true)) {
                $noteEmailRecipientScope = 'assigned_agent';
            }

            $metadataArray['tickets']['active'] = $request->active;
            $metadataArray['tickets']['auto_assignment'] = $request->auto_assignment ?? false;
            $metadataArray['tickets']['assigned_to_specific_agents'] = $request->auto_robinhood ?? false;
            $metadataArray['tickets']['reassign_reopened_chats'] = $request->reassign_reopened_chats;
            $metadataArray['tickets']['allow_agents_to_view_all_chats'] = $request->allow_agents_to_view_all_chats;
            $metadataArray['tickets']['mask_agent_phone_numbers'] = $request->mask_agent_phone_numbers ?? false;
            $metadataArray['tickets']['selected_agents'] = $request->selected_agents;
            $metadataArray['tickets']['note_email_notifications'] = [
                'enabled' => $request->boolean('note_email_notifications_enabled'),
                'recipient_scope' => $noteEmailRecipientScope,
            ];
            $notePlatformRecipientScope = $request->input('note_platform_notifications_recipient_scope', 'assigned_agent');

            if (! in_array($notePlatformRecipientScope, ['assigned_agent', 'all_team'], true)) {
                $notePlatformRecipientScope = 'assigned_agent';
            }

            $metadataArray['tickets']['note_platform_notifications'] = [
                'enabled' => $request->boolean('note_platform_notifications_enabled'),
                'recipient_scope' => $notePlatformRecipientScope,
            ];

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            if (isset($request->selected_agents)) {
                $this->syncAssignedAgents($currentOrganizationId, $request->selected_agents);
            }

            return back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Settings updated successfully'),
                ]
            );
        }
    }

    public function whatsappBusinessProfileUpdate(StoreWhatsappProfile $request)
    {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $organizationId = session()->get('current_organization');
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        if (isset($config['whatsapp'])) {
            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = config('graph.api_version');
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);

            $response = $whatsappService->updateBusinessProfile($request);

            if ($response->success === true) {
                return back()->with(
                    'status', [
                        'type' => 'success',
                        'message' => __('Your whatsapp business profile has been changed successfully!'),
                    ]
                );
            } else {
                return back()->with(
                    'status', [
                        'type' => 'error',
                        'message' => __('Something went wrong! Your business profile could not be updated!'),
                    ]
                );
            }
        }

        return back()->with(
            'status', [
                'type' => 'error',
                'message' => __('Setup your whatsapp integration first!'),
            ]
        );
    }

    public function deleteWhatsappIntegration(Request $request)
    {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $config = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if (isset($config['whatsapp'])) {
            if ($embeddedSignupActive == 1) {
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
                    'message' => __('Your integration has been removed successfully!'),
                ]
            );
        }

        return back()->with(
            'status', [
                'type' => 'error',
                'message' => __('Setup your whatsapp integration first!'),
            ]
        );
    }

    private function saveWhatsappSettings($accessToken, $appId, $phoneNumberId, $wabaId, $subscribeToWebhook = false)
    {
        $organizationId = session()->get('current_organization');
        $apiVersion = config('graph.api_version');

        $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);

        $phoneNumberResponse = $whatsappService->getPhoneNumberId($accessToken, $wabaId);

        if (! $phoneNumberResponse->success) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => $phoneNumberResponse->data->error->message,
                ]
            );
        }

        //Get Phone Number Status
        $phoneNumberStatusResponse = $whatsappService->getPhoneNumberStatus($accessToken, $phoneNumberResponse->data->id);

        if (! $phoneNumberStatusResponse->success) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => $phoneNumberStatusResponse->data->error->message,
                ]
            );
        }

        //Get Account Review Status
        $accountReviewStatusResponse = $whatsappService->getAccountReviewStatus($accessToken, $wabaId);

        if (! $accountReviewStatusResponse->success) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => $accountReviewStatusResponse->data->error->message,
                ]
            );
        }

        //Get business profile
        $businessProfileResponse = $whatsappService->getBusinessProfile($accessToken, $phoneNumberResponse->data->id);

        if (! $businessProfileResponse->success) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => $businessProfileResponse->data->error->message,
                ]
            );
        }
        $organizationConfig = Organization::where('id', $organizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if (! isset($metadataArray['whatsapp']) || ! is_array($metadataArray['whatsapp'])) {
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
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberStatusResponse->data->whatsapp_business_manager_messaging_limit ?? null;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = null;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = null;
        $metadataArray['whatsapp']['whatsapp_business_manager_messaging_limit'] = $phoneNumberStatusResponse->data->whatsapp_business_manager_messaging_limit ?? null;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatusResponse->data->status;
        $metadataArray['whatsapp']['code_verification_status'] = $phoneNumberStatusResponse->data->code_verification_status;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatusResponse->data->account_review_status;

        if (! isset($metadataArray['whatsapp']['business_profile']) || ! is_array($metadataArray['whatsapp']['business_profile'])) {
            $metadataArray['whatsapp']['business_profile'] = [];
        }

        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfileResponse->data->about ?? null;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfileResponse->data->address ?? null;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfileResponse->data->description ?? null;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfileResponse->data->vertical ?? null;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfileResponse->data->email ?? null;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if ($organizationConfig->save()) {
            $whatsappService->syncTemplates($accessToken, $wabaId);

            return back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Whatsapp settings updated successfully'),
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('Something went wrong. Refresh the page and try again'),
                ]
            );
        }
    }

    protected function abortIfDemo()
    {
        $organizationId = session()->get('current_organization');

        if (app()->environment('demo') && $organizationId == 1) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('You cannot perform this action using the demo account. To test this feature, please create your own account.'),
                ]
            );
        }

        return null;
    }

    public function contacts(Request $request)
    {
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
        } elseif ($request->isMethod('post')) {
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
                    'message' => __('Settings updated successfully'),
                ]
            );
        }
    }

    public function updateWhatsappForCoExistence(Request $request)
    {

        $request->validate([
            'sync_history' => 'nullable|in:0,1',
            'sync_contacts' => 'nullable|in:0,1',
        ]);

        // Ensure at least one field exists
        if (! $request->has('sync_history') && ! $request->has('sync_contacts')) {
            return back()->withErrors([
                'sync_settings' => 'At least one of sync_history or sync_contacts is required.',
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
            'type' => 'success',
            'message' => 'Sync settings updated successfully.',
        ]);

    }

    public function labels(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $organization = Organization::findOrFail($organizationId);

        if ($request->isMethod('get')) {
            $labels = $organization->labels()->latest()->get();

            // Add contact count for each label
            $labels->each(function ($label) use ($organizationId) {
                $label->contact_count = Contact::where('organization_id', $organizationId)
                    ->whereHas('labels', function ($query) use ($label) {
                        $query->where('labels.id', $label->id);
                    })
                    ->count();
            });

            return Inertia::render('User/Settings/Label', [
                'title' => __('Settings'),
                'settings' => $organization,
                'labels' => $labels,
                'modules' => Addon::get(),
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
            ContactPageCache::forgetLabels($organizationId);

            return back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Label created successfully'),
                ]
            );
        }
    }

    public function updateLabel(Request $request, Label $label)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'color' => 'required|string|max:7',
        ]);

        $label->update($request->only('title', 'color'));
        ContactPageCache::forgetLabels((int) $label->organization_id);

        return back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Label updated successfully'),
            ]
        );
    }

    public function deleteLabel(Label $label)
    {
        $organizationId = (int) $label->organization_id;
        $label->delete();
        ContactPageCache::forgetLabels($organizationId);

        return back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Label deleted successfully'),
            ]
        );
    }

    public function createGroupFromLabel(Request $request)
    {
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
            ->whereHas('labels', function ($query) use ($request) {
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

        ContactGroupCountService::rebuildGroups([$contactGroup->id]);

        return back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Contact group created successfully with :count contacts', ['count' => $contactsWithLabel->count()]),
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
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($data)) {
            DB::table('assigned_agents')->insert($data);
        }
    }

    public function emailSettings(Request $request)
    {
        $settings = Organization::where('id', session()->get('current_organization'))->first();

        return Inertia::render('User/Settings/Email', [
            'title' => __('Settings'),
            'settings' => $settings,
            'modules' => Addon::get(),
        ]);
    }

    public function updateEmailSettings(Request $request)
    {
        $currentOrganizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        $metadataArray['email_settings'] = [
            'active' => $request->input('active', false),
            'host' => $request->input('host', ''),
            'port' => (int) $request->input('port', 587),
            'username' => $request->input('username', ''),
            'password' => $request->input('password', ''),
            'encryption' => $request->input('encryption', 'tls'),
            'from_name' => $request->input('from_name', ''),
            'from_address' => $request->input('from_address', ''),
        ];

        // Keep existing password if new one is empty
        if (empty($metadataArray['email_settings']['password']) && ! empty($organizationConfig->metadata)) {
            $existing = json_decode($organizationConfig->metadata, true);
            if (! empty($existing['email_settings']['password'])) {
                $metadataArray['email_settings']['password'] = $existing['email_settings']['password'];
            }
        }

        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();

        return response()->json([
            'success' => true,
            'message' => __('Email settings saved successfully'),
        ]);
    }

    public function testEmailSettings(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'encryption' => ['nullable', 'in:tls,ssl,none'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $organizationId = session()->get('current_organization');

        $mailService = new \App\Services\OrganizationMailConfigService();
        $result = $mailService->sendTestEmail(
            $organizationId,
            $validated['email'],
            collect($validated)->except('email')->all()
        );

        return response()->json($result);
    }
}
