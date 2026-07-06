<?php

namespace App\Helpers;
use Cache;

use DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Models\Addon;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;
use Symfony\Component\Mime\Part\HtmlPart;

class CustomHelper {
    public static function config($key){
        $config = Setting::where('key', $key)->first();

        if($config){
            return $config->value;
        } else {
            return NULL;
        }
    }

    public static function isModuleEnabled($module, $organizationId = NULL){
        $orgId = $organizationId != NULL ? $organizationId : session()->get('current_organization');

        if (!$orgId) {
            return false;
        }

        return Cache::remember(
            'module-enabled:' . $orgId . ':' . md5((string) $module),
            now()->addMinutes(5),
            function () use ($module, $orgId) {
                $addon = Addon::where('name', $module)
                    ->where('status', 1)
                    ->where('is_active', 1)
                    ->exists();

                if (!$addon) {
                    return false;
                }

                $subscription = Subscription::query()
                    ->where('organization_id', $orgId)
                    ->orderByDesc('id')
                    ->first(['id', 'plan_id', 'status', 'valid_until']);

                // Allow trial organizations without a selected plan to test enabled addons.
                if ($subscription && $subscription->status === 'trial' && $subscription->valid_until && Carbon::parse($subscription->valid_until)->isFuture()) {
                    return true;
                }

                // Guard against organizations that have no subscription record or selected plan.
                if (!$subscription || !$subscription->plan_id) {
                    return false;
                }

                $planMetadata = SubscriptionPlan::whereKey($subscription->plan_id)
                    ->whereNull('deleted_at')
                    ->value('metadata');

                if (!$planMetadata) {
                    return false; // Plan not found
                }

                $metadata = json_decode($planMetadata, true);

                if (isset($metadata['addons']) && is_array($metadata['addons'])) {
                    // Return true if the module exists and is set to true, otherwise false
                    return isset($metadata['addons'][$module]) && $metadata['addons'][$module] === true;
                }

                return false; // Default to false if addons key or module is not found
            }
        );
    }
}
