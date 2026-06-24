<?php

namespace App\Services;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\PaymentGateway;
use App\Models\SubscriptionPlan;
use App\Services\StripeService;
use DB;

class SubscriptionPlanService
{
    /**
     * Get all subscription plans based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $subscriptionPlans = (new SubscriptionPlan)->listAll($request->query('search'));

        return SubscriptionPlanResource::collection($subscriptionPlans);
    }

    /**
     * Retrieve a subscription plan by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\SubscriptionPlan
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($uuid = null)
    {
        return SubscriptionPlan::where('uuid', $uuid)->first();
    }

    /**
     * Store a new subscription plan based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        $sallaIntegrationEnabled = $request->has('salla_integration_enabled')
            ? (bool) $request->input('salla_integration_enabled')
            : false;
        $shopifyIntegrationEnabled = $request->has('shopify_integration_enabled')
            ? (bool) $request->input('shopify_integration_enabled')
            : false;
        $zidIntegrationEnabled = $request->has('zid_integration_enabled')
            ? (bool) $request->input('zid_integration_enabled')
            : true;
        $woocommerceIntegrationEnabled = $request->has('woocommerce_integration_enabled')
            ? (bool) $request->input('woocommerce_integration_enabled')
            : true;

        $newSubscriptionPlan = SubscriptionPlan::create([
            'name' => $request->input('name'),
            'price' => $request->input('price'),
            'period' => $request->input('period'),
            'status' => $request->input('status'),
            'metadata' => json_encode([
                'campaign_limit' => $request->input('campaign_limit'),
                'message_limit' => $request->input('message_limit'),
                'template_messages_limit' => $request->input('template_messages_limit'),
                'conversations_limit' => $request->input('conversations_limit'),
                'contacts_limit' => $request->input('contacts_limit'),
                'team_limit' => $request->input('team_limit'),
                'organizations_limit' => $request->input('organizations_limit'),
                'receive_messages_after_expiration' => $request->input('receive_messages_after_expiration') == true ? 1 : 0,
                'ai_text_response_limit' => $request->input('ai_text_response_limit'),
                'ai_audio_response_limit' => $request->input('ai_audio_response_limit'),
                'addons' => $request->input('addons', []),
                'salla_integration_enabled' => $sallaIntegrationEnabled,
                'shopify_integration_enabled' => $shopifyIntegrationEnabled,
                'zid_integration_enabled' => $zidIntegrationEnabled,
                'woocommerce_integration_enabled' => $woocommerceIntegrationEnabled
            ]),
        ]);

        $stripe = PaymentGateway::where('name', 'Stripe')->first();

        if($stripe->is_active == '1'){
            //Create product in Stripe
            (new StripeService)->createProduct($newSubscriptionPlan);
        }

        if (file_exists(base_path('modules/Pabbly/Services/PabblyService.php'))) {
            $pabblyService = new \Modules\Pabbly\Services\PabblyService();
            $response = $pabblyService->createPlan($newSubscriptionPlan);
        }
    
        return $newSubscriptionPlan;
    }

    /**
     * Update an existing subscription plan based on the provided request data.
     *
     * @param Request $request
     */
    public function update(Object $request, $uuid)
    {
        $plan = SubscriptionPlan::where('uuid', $uuid)->firstOrFail();
        $metadata = json_decode($plan->metadata, true);
        $metadata['campaign_limit'] = $request->input('campaign_limit');
        $metadata['message_limit'] = $request->input('message_limit');
        $metadata['template_messages_limit'] = $request->input('template_messages_limit');
        $metadata['conversations_limit'] = $request->input('conversations_limit');
        $metadata['contacts_limit'] = $request->input('contacts_limit');
        $metadata['team_limit'] = $request->input('team_limit');
        $metadata['organizations_limit'] = $request->input('organizations_limit');
        $metadata['receive_messages_after_expiration'] = $request->input('receive_messages_after_expiration') == true ? 1 : 0;
        $metadata['ai_text_response_limit'] = $request->input('ai_text_response_limit');
        $metadata['ai_audio_response_limit'] = $request->input('ai_audio_response_limit');
        $metadata['addons'] = $request->input('addons', []);
        $metadata['salla_integration_enabled'] = $request->has('salla_integration_enabled')
            ? (bool) $request->input('salla_integration_enabled')
            : ($metadata['salla_integration_enabled'] ?? false);
        $metadata['shopify_integration_enabled'] = $request->has('shopify_integration_enabled')
            ? (bool) $request->input('shopify_integration_enabled')
            : ($metadata['shopify_integration_enabled'] ?? false);
        $metadata['zid_integration_enabled'] = $request->has('zid_integration_enabled')
            ? (bool) $request->input('zid_integration_enabled')
            : ($metadata['zid_integration_enabled'] ?? true);
        $metadata['woocommerce_integration_enabled'] = $request->has('woocommerce_integration_enabled')
            ? (bool) $request->input('woocommerce_integration_enabled')
            : ($metadata['woocommerce_integration_enabled'] ?? true);

        $plan->name = $request->input('name');
        $plan->price = $request->input('price');
        $plan->metadata = json_encode($metadata);
        $plan->period = $request->input('period');
        $plan->status = $request->input('status');

        $plan->save();

        $stripe = PaymentGateway::where('name', 'Stripe')->first();

        if($stripe->is_active == '1'){
            //Update product in Stripe
            (new StripeService)->updateProduct($plan);
        }

        if (file_exists(base_path('modules/Pabbly/Services/PabblyService.php'))) {
            $pabblyService = new \Modules\Pabbly\Services\PabblyService();
            $response = $pabblyService->updatePlan($plan);
        }

        return $plan;
    }

    /**
     * Destroy (delete) an existing subscription plan based on the provided request data.
     *
     * @param Request $request
     */
    public function destroy($uuid)
    {
        $subscriptionPlan = SubscriptionPlan::where('uuid', $uuid)->firstOrFail();
        $subscriptionPlan->update(['deleted_at' => now()]);

        $stripe = PaymentGateway::where('name', 'Stripe')->first();

        if($stripe->is_active == '1'){
            //Update product in Stripe
            (new StripeService)->deleteProduct($subscriptionPlan);
        }
    }
}
