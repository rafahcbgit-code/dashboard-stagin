<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\Chat;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Template;
use App\Services\SubscriptionUsageService;
use App\Services\DashboardChartService;
use App\Services\DashboardReadinessService;
use App\Services\PreSetupService;
use App\Services\SubscriptionService;
use App\Models\OtpConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Models\Team;

class DashboardController extends BaseController
{
    public function __construct(
        private readonly DashboardReadinessService $readinessService,
        private readonly DashboardChartService $chartService,
        private readonly PreSetupService $preSetupService,
        private readonly SubscriptionUsageService $subscriptionUsageService,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);
        $days = (int) ($validated['days'] ?? 7);
        $organizationId = session()->get('current_organization');
        $team = Team::where('organization_id', $organizationId)->where('user_id', auth()->user()->id)->first();
        $data['role'] = $team->role;

        $phone = OtpConfig::value('contact_phone');
        $phone = ltrim($phone, '+');
        $data['contact_phone'] = $phone;
        $data['whatsapp_link'] = "https://wa.me/{$data['contact_phone']}";
        $data['calendly_url'] = OtpConfig::value('calendly_url');

        $data['subscription'] = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        $data['subscriptionDetails'] = SubscriptionService::calculateSubscriptionBillingDetails($organizationId, $data['subscription']->plan_id);
        $data['subscriptionIsActive'] = SubscriptionService::isSubscriptionActive($organizationId);

        $data['chatCount'] = Cache::remember("org_{$organizationId}_chat_count", 300, function () use ($organizationId) {
            return $this->chatCount($organizationId);
        });

        $data['campaignCount'] = Cache::remember("org_{$organizationId}_campaign_count", 300, function () use ($organizationId) {
            return Campaign::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });

        $data['contactCount'] = Cache::remember("org_{$organizationId}_contact_count", 300, function () use ($organizationId) {
            return Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });

        $data['templateCount'] = Cache::remember("org_{$organizationId}_template_count", 300, function () use ($organizationId) {
            return Template::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });
        $approvedTemplateCount = Cache::remember("org_{$organizationId}_approved_template_count", 300, function () use ($organizationId) {
            return Template::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereIn('status', ['APPROVED', 'ACTIVE'])
                ->count();
        });
        $data['graphAPIVersion'] = config('graph.api_version');

        $organization = Organization::query()->select(['id', 'metadata'])->findOrFail($organizationId);
        $config = json_decode((string) $organization->metadata, true);
        $config = is_array($config) ? $config : [];
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');

        $data['campaigns'] = Campaign::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'scheduled'])
            ->limit(5)
            ->get();
        $data['setupWhatsapp'] = ! is_array($config['whatsapp'] ?? null);
        $data['whatsappReadiness'] = $this->readinessService->check($config);
        $chart = $this->chartService->forOrganization((int) $organizationId, $days);
        $data['period'] = $chart['period'];
        $data['inbound'] = $chart['inbound'];
        $data['outbound'] = $chart['outbound'];
        $data['chartDays'] = $days;

        $preSetup = $this->preSetupService->forOrganization(
            (int) $organizationId,
            $data['role'] === 'agent',
            data_get($config, 'pre_setup.dismissed', false) === true,
            $config,
            [
                'contacts' => $data['contactCount'],
                'templates' => $data['templateCount'],
                'approved_templates' => $approvedTemplateCount,
                'campaigns' => $data['campaignCount'],
            ]
        );
        $data['preSetup'] = $preSetup['steps'];
        $data['preSetupSummary'] = $preSetup['summary'];
        $data['mandatoryReady'] = $preSetup['mandatoryReady'];
        $data['preSetupDismissed'] = $preSetup['dismissed'];
        $data['welcomeKey'] = ((int) $data['chatCount'] === 0 && (int) $data['campaignCount'] === 0) ? 'Welcome' : 'Welcome back';
        $data['resourceUsage'] = $this->subscriptionUsageService->summaries((int) $organizationId, auth()->id());

        $data['embeddedSignupActive'] = CustomHelper::isModuleEnabled('Embedded Signup');
        $data['appId'] = $settings->get('whatsapp_client_id', null);
        $data['configId'] = $settings->get('whatsapp_config_id', null);
        $data['title'] = __('Dashboard');

        return Inertia::render('User/Dashboard', $data);
    }

    public function dismissNotification(Request $request, $type)
    {
        $currentOrganizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $currentOrganizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if ($type === 'team') {
            $metadataArray['notification']['team'] = false;
        }

        $updatedMetadataJson = json_encode($metadataArray);

        $organizationConfig->metadata = $updatedMetadataJson;
        $organizationConfig->save();

        return redirect()->route('dashboard')->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Notification dismissed successfully!')
            ]
        );
    }

    public function updatePreSetupState(Request $request)
    {
        $validated = $request->validate(['dismissed' => ['required', 'boolean']]);
        $organizationId = (int) session()->get('current_organization');
        $organization = Organization::query()->findOrFail($organizationId);
        $metadata = json_decode((string) $organization->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['pre_setup'] = is_array($metadata['pre_setup'] ?? null) ? $metadata['pre_setup'] : [];
        $metadata['pre_setup']['dismissed'] = (bool) $validated['dismissed'];
        $organization->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $organization->save();

        return back();
    }

    private function chatCount($organizationId)
    {
        return Chat::query()
            ->join('contacts', 'chats.contact_id', '=', 'contacts.id')
            ->where('chats.organization_id', $organizationId)
            ->whereNull('chats.deleted_at')
            ->whereNull('contacts.deleted_at')
            ->count();

    }
}
