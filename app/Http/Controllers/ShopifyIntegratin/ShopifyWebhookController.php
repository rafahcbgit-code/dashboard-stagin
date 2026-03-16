<?php

namespace App\Http\Controllers\ShopifyIntegratin;

use App\Http\Controllers\Controller;
use App\Jobs\Shopify\ProcessShopifyWebhookDeliveryJob;
use App\Models\ShopifyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function handleOrderCreated(Request $request)
    {
        $shopDomain = $request->header('x-shopify-shop-domain');

         $data = $request->all();

        Log::info("New Order Received from $shopDomain", ['order is' => $data]);

        $store = ShopifyStore::where('shop_url', $shopDomain)->first();
        if (!$store) {
            Log::warning('Shopify webhook ignored (store not registered)', [
                'shop_domain' => $shopDomain,
                'topic' => $request->header('x-shopify-topic'),
                'event_id' => $request->header('x-shopify-event-id'),
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        ProcessShopifyWebhookDeliveryJob::dispatch(
            $request->getContent(),
            $request->headers->all(),
            $store->id,
            $store->organization_id
        );

        Log::info('New Order Received from Shopify webhook', [
            'shop_domain' => $shopDomain,
            'event_id' => $request->header('x-shopify-event-id'),
            'organization_id' => $store->organization_id,
        ]);

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Handle Shopify Order Updated Webhook
     */
    public function handleOrderUpdated(Request $request)
    {
        return $this->processWebhook($request, 'orders/updated');
    }

    /**
     * Handle Shopify Order Fulfilled Webhook
     */
    public function handleOrderFulfilled(Request $request)
    {
        return $this->processWebhook($request, 'orders/fulfilled');
    }

    /**
     * Common logic to handle Shopify webhooks
     * This keeps your code clean (DRY - Don't Repeat Yourself)
     */
    private function processWebhook(Request $request, $topic)
    {
        $shopDomain = $request->header('x-shopify-shop-domain');
        $eventId = $request->header('x-shopify-event-id');

        // Find the store to get the context (organization_id)
        $store = ShopifyStore::where('shop_url', $shopDomain)->first();

        if (!$store) {
            Log::warning("Shopify webhook ignored ($topic) - Store not registered", [
                'shop_domain' => $shopDomain,
                'topic' => $topic,
                'event_id' => $eventId,
            ]);
            return response()->json(['status' => 'ignored'], 200);
        }

        // Dispatch the job for background processing
        // We pass the raw content and headers to be verified inside the job if needed
        ProcessShopifyWebhookDeliveryJob::dispatch(
            $request->getContent(),
            $request->headers->all(),
            $store->id,
            $store->organization_id
        );

        Log::info("Shopify Webhook Received: $topic", [
            'shop_domain' => $shopDomain,
            'event_id' => $eventId,
            'organization_id' => $store->organization_id,
        ]);

        return response()->json(['status' => 'success'], 200);
    }
}
