<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWhatsappSettings;
use App\Helpers\CustomHelper;
use App\Http\Requests\StoreWhatsappProfile;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Models\Subscription;
use App\Models\ZidWebhookSubscription;
use App\Services\ContactFieldService;
use App\Services\WhatsappService;
use App\Services\WooCommerce\WooCommerceIntegrationService;
use App\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Validator;

class PluginController extends BaseController
{
    public function __construct(
        private WooCommerceIntegrationService $wooCommerceIntegrationService
    ) {
    }

    public function index(Request $request){
        if ($request->isMethod('get')) {
            $organizationId = session()->get('current_organization');
            $subscription = Subscription::with('plan')
                ->where('organization_id', $organizationId)
                ->first();
            $plan = $subscription?->plan;
            $currentPlanName = $plan?->name ?? null;
            $shopifyAccessAllowed = true;

            $planMetadata = [];
            if ($plan && $plan->metadata) {
                $planMetadata = json_decode($plan->metadata, true) ?? [];
                if (array_key_exists('shopify_integration_enabled', $planMetadata)) {
                    $shopifyAccessAllowed = (bool) $planMetadata['shopify_integration_enabled'];
                }
            }
            $zidAccessAllowed = true;
            if ($plan && $plan->metadata) {
                if (array_key_exists('zid_integration_enabled', $planMetadata)) {
                    $zidAccessAllowed = (bool) $planMetadata['zid_integration_enabled'];
                }
            }

            $data['title'] = __('Plugin Settings');
            $data['modules'] = Addon::get();
            $allModules = Addon::where('category', 'plugins')->where('is_active', 1)->where('status', 1)->get();
            
            // Filter modules based on user's plan
            $data['plugins'] = $allModules->filter(function($module) {
                return CustomHelper::isModuleEnabled($module->name);
            })->map(function($module) {
                // Add download URL for each module
                if (strtolower($module->name) === 'woocommerce') {
                    // Get WooCommerce download link from settings
                    $woocommerceLink = Setting::where('key', 'woocommerce_plugin_link')->value('value');
                    $module->download_url = $woocommerceLink ?: route('plugin.download', ['name' => strtolower($module->name)]);
                } else {
                    $module->download_url = route('plugin.download', ['name' => strtolower($module->name)]);
                }
                return $module;
            });
            $data['shopifyAccessAllowed'] = $shopifyAccessAllowed;
            $data['currentPlanName'] = $currentPlanName;
            $zidContext = $this->buildZidIntegrationContext($organizationId);
            $data = array_merge($data, $zidContext);
            $data['zidSettingsUrl'] = route('settings.zid');
            $data['woocommerceIntegration'] = $this->wooCommerceIntegrationService->getForOrganization($organizationId);
            
            return Inertia::render('User/Settings/Plugins/Index', $data);
        }
    }

    public function zidSettings(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $context = $this->buildZidIntegrationContext($organizationId);
        $context['modules'] = Addon::get();
        $context['zidAutomationsUrl'] = route('zid.automations.index');

        return Inertia::render('User/Settings/ZidIntegrationOverview', $context);
    }

    private function buildZidIntegrationContext(int $organizationId): array
    {
        $subscription = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        $planMetadata = [];
        $zidAccessAllowed = true;

        if ($subscription && $subscription->plan && $subscription->plan->metadata) {
            $planMetadata = json_decode($subscription->plan->metadata, true) ?? [];
            if (array_key_exists('zid_integration_enabled', $planMetadata)) {
                $zidAccessAllowed = (bool) $planMetadata['zid_integration_enabled'];
            }
        }

        $connection = IntegrationConnection::where('organization_id', $organizationId)
            ->where('platform', config('zid.platform'))
            ->first();

        $connectionPayload = $connection ? [
            'id' => $connection->id,
            'store_id' => $connection->store_id,
            'store_name' => $connection->store_name,
            'connection_status' => $connection->connection_status,
            'token_expires_at' => $connection->token_expires_at?->toDateTimeString(),
            'connected_at' => $connection->connected_at?->toDateTimeString(),
        ] : null;

        $eventConfig = config('zid.webhook_events', []);
        $events = collect($eventConfig)->mapWithKeys(function ($meta, $name) {
            $details = $this->getZidEventMetadata()[$name] ?? [];
            return [
                $name => array_merge($meta, [
                    'label' => $details['label'] ?? $name,
                    'description' => $details['description'] ?? '',
                ]),
            ];
        })->toArray();

        $eventStatuses = [];

        if ($connection) {
            $subscriptions = ZidWebhookSubscription::where('store_id', $connection->store_id)->get()->keyBy('event_name');

            foreach ($events as $eventName => $meta) {
                /** @var ZidWebhookSubscription|null $sub */
                $sub = $subscriptions->get($eventName);
                $eventStatuses[$eventName] = [
                    'enabled' => $sub ? $sub->status === 'active' : false,
                    'condition_count' => $sub ? count($sub->conditions_json ?? []) : 0,
                ];
            }
        } else {
            foreach ($events as $eventName => $meta) {
                $eventStatuses[$eventName] = [
                    'enabled' => false,
                    'condition_count' => 0,
                ];
            }
        }

        return [
            'zidConnection' => $connectionPayload,
            'zidConnectUrl' => route('zid.connect'),
            'zidDisconnectUrl' => route('zid.disconnect'),
            'zidSubscriptionEvents' => $events,
            'zidSubscriptionStatus' => $eventStatuses,
            'zidSubscriptionToggleUrl' => route('zid.subscriptions.update'),
            'zidAccessAllowed' => $zidAccessAllowed,
        ];
    }

    public function download($name)
    {
        $plugin = Addon::where('name', $name)
            ->where('category', 'plugins')
            ->where('is_active', 1)
            ->firstOrFail();

        // Check if user has access to this plugin
        if (!CustomHelper::isModuleEnabled($plugin->name)) {
            abort(403, 'You do not have access to this plugin');
        }

        if (strtolower($name) === 'woocommerce') {
            $link = Setting::where('key', 'woocommerce_plugin_link')->value('value');
            $name = Setting::where('key', 'woocommerce_plugin_name')->value('value') . '-' . 'woocommerce';
            if (!$link) {
                abort(404, 'WooCommerce plugin link not found');
            }
            // Remove /storage prefix from the link
            $link = str_replace('/storage/', '', $link);
            // Convert storage path to full path
            $pluginPath = storage_path('app/public/' . $link);
        } else {
            $pluginPath = base_path('plugins/' . strtolower($name) . '.zip');
        }

        if (!file_exists($pluginPath)) {
            abort(404, 'Plugin file not found');
        }

        return response()->download($pluginPath, $name . '.zip');
    }

    private function getZidEventMetadata(): array
    {
        return [
            'order.create' => [
                'label' => __('New Order Created'),
                'description' => __('Fires when a new order is submitted on Zid.'),
            ],
            'order.status.update' => [
                'label' => __('Order Status Updated'),
                'description' => __('Fires when Zid changes an order status.'),
            ],
            'abandoned_cart.created' => [
                'label' => __('Abandoned Cart'),
                'description' => __('Fires when a shopper leaves checkout without submitting.'),
            ],
            'customer.login' => [
                'label' => __('Customer Login'),
                'description' => __('Fires when a customer logs into their Zid account.'),
            ],
        ];
    }
}
