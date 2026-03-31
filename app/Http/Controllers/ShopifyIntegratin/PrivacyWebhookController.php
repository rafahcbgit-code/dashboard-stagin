<?php

namespace App\Http\Controllers\ShopifyIntegratin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class PrivacyWebhookController extends Controller
{


    public function handle(Request $request)
    {
        Log::info("Shopify is checking handle(PrivacyWebhookController): " . $request->header('X-Shopify-Topic'));

        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        // 1. التحقق من أمان الطلب (Mandatory!)
        if (!$this->verifyHmac($request->getContent(), $hmac)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);

        switch ($topic) {
            case 'customers/data_request':
                // طلب استعراض بيانات عميل: ابحث عن بيانات العميل في ChatBerry وأرسلها لإيميل المتجر
                // Log::info("Data request for customer: " . $data['customer']['email']);
                break;

            case 'customers/redact':
                // طلب حذف بيانات عميل: احذف اسم وهاتف العميل من سجلات الواتساب لديك
                // Log::info("Redacting customer data for: " . $data['customer']['id']);
                break;

            case 'shop/redact':
                // طلب حذف بيانات متجر: التاجر حذف تطبيقك، احذف الـ Access Token وكل بياناته
                // Log::info("Deleting all data for shop: $shopDomain");
                break;
        }

        // يجب دائماً الرد بـ 200 OK فوراً
        return response()->json([], 200);
    }

    private function verifyHmac($data, $hmac)
    {
        $apiSecret = config('services.shopify.api_secret');

        // الطريقة التي تستخدمها شوبيفاي (Base64)
        $calculatedBase64 = base64_encode(hash_hmac('sha256', $data, $apiSecret, true));

        // الطريقة التي تخرجها المواقع الخارجية وPostman أحياناً (Hex)
        $calculatedHex = hash_hmac('sha256', $data, $apiSecret);

        // التحقق من كلا التنسيقين لضمان نجاح الاختبار
        return hash_equals((string)$hmac, $calculatedBase64) || hash_equals((string)$hmac, $calculatedHex);
    }
}
