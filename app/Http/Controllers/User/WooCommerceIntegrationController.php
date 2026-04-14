<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWooCommerceIntegration;
use App\Services\WooCommerce\DTOs\WooCommerceCredentials;
use App\Services\WooCommerce\WooCommerceIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceIntegrationController extends BaseController
{
    public function __construct(
        private WooCommerceIntegrationService $integrationService
    ) {
    }

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
                'message' => __('WooCommerce connection failed: ') . $exception->getMessage(),
            ], 422);
        }
    }
}
