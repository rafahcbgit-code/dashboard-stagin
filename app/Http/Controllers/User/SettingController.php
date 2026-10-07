<?php

namespace App\Http\Controllers\User;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWhatsappProfile;
use App\Http\Requests\StoreWhatsappSettings;
use App\Jobs\SyncWhatsappTemplatesJob;
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
use App\Services\WhatsappTimezoneSuggestionService;
use App\Support\ContactPageCache;
use App\Support\WhatsappTemplateSyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SettingController extends BaseController
{
    private const SYNC_TEMPLATES_ON_WHATSAPP_REFRESH = false;

    protected $contactFieldService;
    protected $whatsappTimezoneSuggestionService;

    public function __construct(
        ContactFieldService $contactFieldService,
        WhatsappTimezoneSuggestionService $whatsappTimezoneSuggestionService
    ) {
        $this->contactFieldService = $contactFieldService;
        $this->whatsappTimezoneSuggestionService = $whatsappTimezoneSuggestionService;
    }

    private function suggestedTimezoneForOrganization(?Organization $organization): ?string
    {
        $metadata = json_decode($organization?->metadata ?? '{}', true) ?: [];

        if (is_string(data_get($metadata, 'timezone')) && trim(data_get($metadata, 'timezone')) !== '') {
            return null;
        }

        return $this->whatsappTimezoneSuggestionService->fromPhoneNumber(
            data_get($metadata, 'whatsapp.display_phone_number')
        );
    }

    public function index(Request $request, $display = null)
    {
        if ($request->isMethod('get')) {
            $organizationId = session()->get('current_organization');
            $data['title'] = __('Settings');
            $data['settings'] = Organization::where('id', $organizationId)->first();
            $data['timezones'] = config('formats.timezones');
            $data['countries'] = config('formats.countries');
            $data['suggestedTimezone'] = $this->suggestedTimezoneForOrganization($data['settings']);
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
        $data['timezones'] = config('formats.timezones');
        $data['countries'] = config('formats.countries');
        $data['suggestedTimezone'] = $this->suggestedTimezoneForOrganization($data['settings']);
        $data['modules'] = Addon::get();
        $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);

        return Inertia::render('User/Settings/General', $data);
    }

    public function viewWhatsappSettings(Request $request)
    {
        $organization = Organization::where('id', session()->get('current_organization'))->first();
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');
        $metadata = json_decode($organization?->metadata ?: '{}', true) ?: [];
        $wabaId = (string) data_get($metadata, 'whatsapp.waba_id', '');

        $data = [
            'embeddedSignupActive' => CustomHelper::isModuleEnabled('Embedded Signup'),
            'graphAPIVersion' => config('graph.api_version'),
            'appId' => $settings->get('whatsapp_client_id', null),
            'configId' => $settings->get('whatsapp_config_id', null),
            'settings' => $organization,
            'templateSyncEnabled' => self::SYNC_TEMPLATES_ON_WHATSAPP_REFRESH,
            'templateSyncStatus' => self::SYNC_TEMPLATES_ON_WHATSAPP_REFRESH && $wabaId !== ''
                ? WhatsappTemplateSyncStatus::get((int) $organization->id, $wabaId)
                : ['status' => 'idle'],
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
        $requestStartedAt = hrtime(true);
        $preflightStartedAt = hrtime(true);
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];
        $preflightDurationMs = (int) round((hrtime(true) - $preflightStartedAt) / 1_000_000);

        return $this->saveWhatsappSettings(
            $config['whatsapp']['access_token'] ?? null,
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null,
            false,
            self::SYNC_TEMPLATES_ON_WHATSAPP_REFRESH ? 'queued' : 'skip',
            $requestStartedAt,
            $preflightDurationMs,
            true
        );
    }

    public function whatsappTemplateSyncStatus()
    {
        $organizationId = (int) session()->get('current_organization');
        $organization = Organization::findOrFail($organizationId);
        $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
        $wabaId = (string) data_get($metadata, 'whatsapp.waba_id', '');

        if ($wabaId === '') {
            return response()->json(['status' => 'idle']);
        }

        return response()->json(WhatsappTemplateSyncStatus::get($organizationId, $wabaId));
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
            $previousWords = (string) ($metadataArray['campaign_settings']['unsubscribe_trigger'] ?? '');
            $history = is_array($metadataArray['campaign_settings']['unsubscribe_trigger_history'] ?? null)
                ? $metadataArray['campaign_settings']['unsubscribe_trigger_history']
                : [];
            $history = array_values(array_unique(array_filter(array_merge(
                $history,
                preg_split('/\s*,\s*/u', $previousWords, -1, PREG_SPLIT_NO_EMPTY) ?: []
            ))));
            $metadataArray['campaign_settings']['unsubscribe_trigger'] = $words;
            $metadataArray['campaign_settings']['unsubscribe_trigger_history'] = array_slice($history, -25);

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

    private function saveWhatsappSettings(
        $accessToken,
        $appId,
        $phoneNumberId,
        $wabaId,
        $subscribeToWebhook = false,
        string $templateSyncMode = 'sync',
        ?int $requestStartedAt = null,
        int $preflightDurationMs = 0,
        bool $useConfiguredPhoneNumber = false
    )
    {
        $requestStartedAt ??= hrtime(true);
        $organizationId = session()->get('current_organization');
        $apiVersion = config('graph.api_version');

        $serviceInitStartedAt = hrtime(true);
        $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
        $serviceInitDurationMs = (int) round((hrtime(true) - $serviceInitStartedAt) / 1_000_000);

        $phoneLookupDurationMs = 0;
        if (! $useConfiguredPhoneNumber) {
            $phoneLookupStartedAt = hrtime(true);
            $phoneNumberResponse = $whatsappService->getPhoneNumberId($accessToken, $wabaId);
            $phoneLookupDurationMs = (int) round((hrtime(true) - $phoneLookupStartedAt) / 1_000_000);

            if (! $phoneNumberResponse->success) {
                return back()->with(
                    'status', [
                        'type' => 'error',
                        'message' => $phoneNumberResponse->data->error->message,
                    ]
                );
            }

            $resolvedPhoneNumberId = (string) $phoneNumberResponse->data->id;
            $phoneNumberData = $phoneNumberResponse->data;
        } else {
            $resolvedPhoneNumberId = trim((string) $phoneNumberId);
            if ($resolvedPhoneNumberId === '') {
                return back()->with(
                    'status', [
                        'type' => 'error',
                        'message' => __('Phone number unavailable'),
                    ]
                );
            }

            $phoneNumberData = null;
        }

        // These resources depend on the resolved phone number but are independent
        // from each other, so fetch them concurrently.
        $syncDataStartedAt = hrtime(true);
        $syncDataResponse = $whatsappService->getPhoneNumberSyncData(
            $resolvedPhoneNumberId,
            (string) $wabaId
        );
        $syncDataDurationMs = (int) round((hrtime(true) - $syncDataStartedAt) / 1_000_000);

        if (! $syncDataResponse->success) {
            return back()->with(
                'status', [
                    'type' => 'error',
                    'message' => $syncDataResponse->message,
                ]
            );
        }

        $phoneNumberStatus = $syncDataResponse->data->phone_status;
        $accountReviewStatus = $syncDataResponse->data->account_review;
        $businessProfile = $syncDataResponse->data->business_profile;
        if ($useConfiguredPhoneNumber) {
            $phoneNumberData = $phoneNumberStatus;
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
        $metadataArray['whatsapp']['phone_number_id'] = $resolvedPhoneNumberId;
        $metadataArray['whatsapp']['display_phone_number'] = $phoneNumberData->display_phone_number ?? null;
        $metadataArray['whatsapp']['verified_name'] = $phoneNumberData->verified_name ?? null;
        $metadataArray['whatsapp']['quality_rating'] = $phoneNumberData->quality_rating ?? null;
        $metadataArray['whatsapp']['name_status'] = $phoneNumberData->name_status ?? null;
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberStatus->whatsapp_business_manager_messaging_limit ?? null;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = null;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = null;
        $metadataArray['whatsapp']['whatsapp_business_manager_messaging_limit'] = $phoneNumberStatus->whatsapp_business_manager_messaging_limit ?? null;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatus->status ?? null;
        $metadataArray['whatsapp']['code_verification_status'] = $phoneNumberStatus->code_verification_status ?? null;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatus->account_review_status ?? null;

        if (! isset($metadataArray['whatsapp']['business_profile']) || ! is_array($metadataArray['whatsapp']['business_profile'])) {
            $metadataArray['whatsapp']['business_profile'] = [];
        }

        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfile->about ?? null;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfile->address ?? null;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfile->description ?? null;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfile->vertical ?? null;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfile->email ?? null;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        $metadataSaveStartedAt = hrtime(true);
        if ($organizationConfig->save()) {
            $metadataSaveDurationMs = (int) round((hrtime(true) - $metadataSaveStartedAt) / 1_000_000);
            $templateSyncQueued = false;
            if ($templateSyncMode === 'queued') {
                WhatsappTemplateSyncStatus::update((int) $organizationId, (string) $wabaId, 'queued');
                try {
                    SyncWhatsappTemplatesJob::dispatch((int) $organizationId, (string) $wabaId)
                        ->onConnection('redis')
                        ->onQueue('whatsapp-number-status');
                    $templateSyncQueued = true;
                } catch (\Throwable $exception) {
                    WhatsappTemplateSyncStatus::update((int) $organizationId, (string) $wabaId, 'failed');
                    report($exception);
                }
            } elseif ($templateSyncMode === 'sync') {
                $whatsappService->syncTemplates($accessToken, $wabaId);
            }

            $webhookSyncQueued = false;
            if ($templateSyncMode === 'skip' && (int) ($metadataArray['whatsapp']['is_embedded_signup'] ?? 0) === 1) {
                try {
                    \App\Jobs\OverrideWhatsappWabaCallbackJob::dispatch((int) $organizationId)
                        ->onConnection('redis')
                        ->onQueue('whatsapp-number-status');
                    $webhookSyncQueued = true;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            if ($templateSyncMode === 'skip') {
                Log::info('WhatsApp settings refresh performance', [
                    'organization_id' => (int) $organizationId,
                    'total_ms' => (int) round((hrtime(true) - $requestStartedAt) / 1_000_000),
                    'embedded_preflight_ms' => $preflightDurationMs,
                    'service_init_ms' => $serviceInitDurationMs,
                    'phone_number_lookup_ms' => $phoneLookupDurationMs,
                    'parallel_meta_requests_ms' => $syncDataDurationMs,
                    'meta_request_timings_ms' => $syncDataResponse->request_timings_ms ?? [],
                    'metadata_save_ms' => $metadataSaveDurationMs,
                    'webhook_sync' => $webhookSyncQueued ? 'queued' : 'not_queued',
                    'template_sync' => 'skipped',
                ]);
            }

            return back()->with(
                'status', [
                    'type' => 'success',
                    'message' => match ($templateSyncMode) {
                        'queued' => $templateSyncQueued
                            ? __('WhatsApp details updated. Templates are syncing in the background.')
                            : __('WhatsApp details updated, but template synchronization could not be queued.'),
                        'skip' => __('Whatsapp settings updated successfully'),
                        default => __('Whatsapp settings updated successfully'),
                    },
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
            $contactCounts = DB::table('contact_label')
                ->join('contacts', 'contacts.id', '=', 'contact_label.contact_id')
                ->where('contacts.organization_id', $organizationId)
                ->whereNull('contacts.deleted_at')
                ->whereIn('contact_label.label_id', $labels->modelKeys())
                ->select('contact_label.label_id', DB::raw('COUNT(DISTINCT contact_label.contact_id) as contact_count'))
                ->groupBy('contact_label.label_id')
                ->pluck('contact_count', 'label_id');

            $labels->each(function ($label) use ($contactCounts) {
                $label->contact_count = (int) ($contactCounts[$label->id] ?? 0);
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
        $metadata = $settings?->metadata ? json_decode($settings->metadata, true) : [];
        $emailSettings = is_array($metadata['email_settings'] ?? null) ? $metadata['email_settings'] : [];
        $hasSavedEmailPassword = ! empty($emailSettings['password']);
        unset($emailSettings['password']);

        return Inertia::render('User/Settings/Email', [
            'title' => __('Settings'),
            'emailSettings' => $emailSettings,
            'hasSavedEmailPassword' => $hasSavedEmailPassword,
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
