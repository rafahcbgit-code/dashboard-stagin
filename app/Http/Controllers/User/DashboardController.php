<?php

namespace App\Http\Controllers\User;

use Carbon\Carbon;
use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Helpers\SubscriptionHelper;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Template;
use App\Services\SubscriptionService;
use App\Models\OtpConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Team;

class DashboardController extends BaseController
{
    protected $subscriptionService;
    public function __construct()
    {
        $this->subscriptionService = new SubscriptionService();
    }

    public function index(Request $request)
    {
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

        $data['chatCount'] = \Illuminate\Support\Facades\Cache::remember("org_{$organizationId}_chat_count", 300, function () use ($organizationId) {
            return $this->chatCount($organizationId);
        });

        $data['campaignCount'] = \Illuminate\Support\Facades\Cache::remember("org_{$organizationId}_campaign_count", 300, function () use ($organizationId) {
            return Campaign::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });

        $data['contactCount'] = \Illuminate\Support\Facades\Cache::remember("org_{$organizationId}_contact_count", 300, function () use ($organizationId) {
            return Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });

        $data['templateCount'] = \Illuminate\Support\Facades\Cache::remember("org_{$organizationId}_template_count", 300, function () use ($organizationId) {
            return Template::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        });
        $data['graphAPIVersion'] = config('graph.api_version');

        $organization = Organization::where('id', $organizationId)->first();
        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');

        $data['organization'] = $organization;
        $data['campaigns'] = Campaign::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'scheduled'])
            ->limit(5)
            ->get();
        $data['setupWhatsapp'] = isset($config['whatsapp']) ? false : true;;
        $data['period'] = $this->period();
        $data['inbound'] = $this->getChatCounts('inbound');
        $data['outbound'] = $this->getChatCounts('outbound');

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

    private function period()
    {
        $currentDate = Carbon::now();
        $dateArray = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDate->startOfDay();
            $dateArray[] = $currentDate->format('Y-m-d\TH:i:s.000\Z');
            $currentDate->subDay();
        }

        $dateArray = array_reverse($dateArray);

        return $dateArray;
    }

    /*private function getChatCounts($type){
        $organizationId = session()->get('current_organization');
        $chatCounts = [];

        foreach ($this->period() as $dateString) {
            $date = Carbon::parse($dateString);
            $chatCount = Chat::where('organization_id', $organizationId)
                ->where('type', $type)
                ->whereNull('deleted_at')
                ->whereDate('created_at', $date->toDateString())
                ->count();
            $chatCounts[] = $chatCount;
        }

        return $chatCounts;
    }*/

    private function getChatCounts($type)
    {
        $organizationId = session()->get('current_organization');
        $period = $this->period();

        $startDate = Carbon::parse($period[0])->startOfDay();
        $endDate   = Carbon::parse(end($period))->endOfDay();

        $stats = Chat::where('organization_id', $organizationId)
            ->where('type', $type)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $chatCounts = [];
        foreach ($period as $dateString) {
            $dateKey = Carbon::parse($dateString)->format('Y-m-d');

            $chatCounts[] = $stats[$dateKey] ?? 0;
        }

        return $chatCounts;
    }

    private function chatCount($organizationId)
    {
        return Chat::query()
            ->join('contacts', 'chats.contact_id', '=', 'contacts.id')
            ->where('chats.organization_id', $organizationId)
            ->whereNull('chats.deleted_at')
            ->whereNull('contacts.deleted_at')
            ->count();

        /*Chat::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereHas('contact', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->count();*/
    }
}
