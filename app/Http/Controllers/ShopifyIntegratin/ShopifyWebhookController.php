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
}
