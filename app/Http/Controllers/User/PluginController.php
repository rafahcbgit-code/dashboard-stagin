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
use App\Services\ContactFieldService;
use App\Services\WhatsappService;
use App\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Validator;

class PluginController extends BaseController
{
    public function index(Request $request){
        if ($request->isMethod('get')) {
            $organizationId = session()->get('current_organization');
            $subscription = Subscription::with('plan')
                ->where('organization_id', $organizationId)
                ->first();
            $plan = $subscription?->plan;
            $currentPlanName = $plan?->name ?? null;
            $shopifyAccessAllowed = true;

            if ($plan && $plan->metadata) {
                $planMetadata = json_decode($plan->metadata, true) ?? [];
                if (array_key_exists('shopify_integration_enabled', $planMetadata)) {
                    $shopifyAccessAllowed = (bool) $planMetadata['shopify_integration_enabled'];
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
            $connection = IntegrationConnection::where('organization_id', $organizationId)
                ->where('platform', config('zid.platform'))
                ->first();

            $data['zidConnection'] = $connection ? [
                'id' => $connection->id,
                'store_id' => $connection->store_id,
                'store_name' => $connection->store_name,
                'connection_status' => $connection->connection_status,
                'token_expires_at' => $connection->token_expires_at?->toDateTimeString(),
                'connected_at' => $connection->connected_at?->toDateTimeString(),
            ] : null;

            $data['zidConnectUrl'] = route('zid.connect');
            $data['zidDisconnectUrl'] = route('zid.disconnect');
            
            return Inertia::render('User/Settings/Plugins/Index', $data);
        }
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
}
