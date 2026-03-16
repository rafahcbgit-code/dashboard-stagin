<?php

namespace App\Http\Controllers\ShopifyIntegratin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function handleOrderCreated(Request $request)
    {
        $shopDomain = $request->header('x-shopify-shop-domain');
        $data = $request->all();

        Log::info("New Order Received from $shopDomain", ['order is' => $data]);

        // هنا تبدأ مهمة ChatBerry:
        // 1. ابحث عن المتجر في قاعدة بياناتك باستخدام $shopDomain لتعرف الـ organization_id
        // 2. استخرج رقم هاتف العميل من $data['customer']['phone']
        // 3. أرسل رسالة الواتساب عبر API الخاص بـ ChatBerry
        
        return response()->json(['status' => 'success'], 200);
    }
}
