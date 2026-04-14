<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWooCommerceIntegration;
use App\Models\WooCommerceWebhookSubscription;
use App\Services\WooCommerce\DTOs\WooCommerceCredentials;
use App\Services\WooCommerce\WooCommerceIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WooCommerceIntegrationController extends BaseController
{
    public function __construct(
        private WooCommerceIntegrationService $integrationService
    ) {
    }

    /**
     * Persist consumer credentials.
     */
    public function store(StoreWooCommerceIntegration $request)
    {
        $organizationId = session()->get('current_organization');
        $user = $request->user();

        if (!$organizationId || !$user) {
            abort(403, __('Organization context is missing'));
        }

        $credentials = WooCommerceCredentials::fromRequest($request);
        $this->integrationService->storeCredentials($credentials, $organizationId, $user->id);

        return redirect()->route('settings.plugins')->with('status', [
            'message' => __('WooCommerce credentials saved successfully.'),
            'type' => 'success',
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $organizationId = session()->get('current_organization');
        $integration = $this->integrationService->getForOrganization($organizationId);

        if (!$integration) {
            return response()->json([
                'message' => __('No WooCommerce credentials were found for your organization.'),
            ], 404);
        }

        try {
            $this->integrationService->testConnection($integration);

            return response()->json([
                'message' => __('WooCommerce connection succeeded.'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('WooCommerce test connection failed', [
                'organization_id' => $organizationId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => __('WooCommerce connection failed.'),
                'reason' => $exception->getMessage(),
            ], 422);
        }
    }

    public function createSubscription(Request $request): JsonResponse
    {
        $organizationId = session()->get('current_organization');
        $integration = $this->integrationService->getForOrganization($organizationId);

        if (!$integration) {
            return response()->json([
                'message' => __('No WooCommerce credentials were found for your organization.'),
            ], 404);
        }

        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(['order.created', 'action.woocommerce_order_status_changed', 'action.woocommerce_add_to_cart'])],
        ]);

        try {
            $subscription = $this->integrationService->createTopicSubscription($integration, $data['topic']);

            WooCommerceWebhookSubscription::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'topic' => $data['topic'],
                ],
                [
                    'user_id' => $request->user()?->id,
                    'integration_id' => $integration->id,
                    'name' => $subscription['name'] ?? ucfirst(str_replace('.', ' ', $data['topic'])),
                    'delivery_url' => config('woocommerce.webhook_delivery_url'),
                    'secret_hash' => Hash::make($integration->webhook_secret),
                    'status' => $subscription['status'] ?? 'active',
                    'response_payload' => $subscription,
                    'webhook_id' => $subscription['id'] ?? null,
                    'is_active' => true,
                ]
            );

            return response()->json([
                'message' => __('WooCommerce subscription created successfully.'),
                'subscription' => [
                    'topic' => $data['topic'],
                    'status' => $subscription['status'] ?? 'active',
                    'webhook_id' => $subscription['id'] ?? null,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('WooCommerce subscription creation failed', [
                'organization_id' => $organizationId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to create WooCommerce subscription.'),
                'reason' => $exception->getMessage(),
            ], 422);
        }
    }

    public function deleteSubscription(Request $request, string $topic): JsonResponse
    {
        $organizationId = session()->get('current_organization');
        $integration = $this->integrationService->getForOrganization($organizationId);

        if (!$integration) {
            return response()->json([
                'message' => __('No WooCommerce credentials were found for your organization.'),
            ], 404);
        }

        $subscription = WooCommerceWebhookSubscription::where('organization_id', $organizationId)
            ->where('topic', $topic)
            ->first();

        if (!$subscription || !$subscription->webhook_id) {
            return response()->json([
                'message' => __('Subscription not found.'),
            ], 404);
        }

        try {
            $this->integrationService->deleteWebhookSubscription($integration, $subscription->webhook_id);
            $subscription->delete();

            return response()->json([
                'message' => __('WooCommerce subscription deleted successfully.'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('WooCommerce subscription deletion failed', [
                'organization_id' => $organizationId,
                'topic' => $topic,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to delete WooCommerce subscription.'),
                'reason' => $exception->getMessage(),
            ], 422);
        }
    }
}
