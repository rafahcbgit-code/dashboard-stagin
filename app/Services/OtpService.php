<?php

namespace App\Services;

use App\Http\Resources\OtpConfigResource;
use App\Models\Module;
use App\Models\RolePermission;
use App\Models\OtpCode;
use App\Models\OtpConfig;
use App\Models\Organization;
use App\Models\Template;
use Carbon\Carbon;

class OtpService
{

    public function get(object $request)
    {
        $data = (new OtpConfig)->listAll($request->query('search'));

        return OtpConfigResource::collection($data);
    }

     public function getByUuid($uuid)
    {
        $role = $uuid ? OtpConfig::where('uuid', $uuid)->first() : null;

        $modules = Module::all();

        return ['role' => $role, 'modules' => $modules];
    }

    public function updateOrCreateConfig(array $data): OtpConfig
    {
        $match = [
            'uuid' => $data['uuid'],
        ];

        $values = [
            'template_name' => $data['template_name'],
            'access_token' => $data['access_token'],
        ];

        return OtpConfig::updateOrCreate($match, $values);
    }

    public function generateAndSaveOtp(string $phone, int $length = 6, int $ttlMinutes = 5): string
    {
        // 1. حذف الأكواد القديمة
        OtpCode::where('phone', $phone)->delete();

        // 2. توليد الكود (المنطق الموحد)
        // استخدام الطريقة الأكثر أمانًا والقابلة للضبط
        $max = (int) str_repeat('9', $length);
        $otpCode = str_pad(rand(0, $max), $length, '0', STR_PAD_LEFT);
        
        // 3. تحديد تاريخ الانتهاء
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        // 4. الحفظ
        OtpCode::create([
            'phone' => $phone,
            'code' => $otpCode,
            'expires_at' => $expiresAt,
        ]);

        return $otpCode;
    }
    
    /**
     * جلب إعدادات الإرسال (المنظمة والقالب)
     *
     * @return array|null [organization_id, template_name, language]
     */
    public function getSenderConfig(): ?array
    {
        // تم توحيد منطق جلب الإعدادات المكرر
        $orgConfig = OtpConfig::first();

        if (!$orgConfig) {
            // يجب التعامل مع حالة عدم وجود إعدادات
            return null;
        }

        $organization = Organization::select("id")->where('uuid', $orgConfig->uuid)->first();

        if (!$organization) {
             return null;
        }

        $template = Template::select('language')->where('organization_id', $organization->id)
            ->where('name', $orgConfig->template_name)->first();
            
        return [
            'organization_id' => $organization->id,
            'template_name' => $orgConfig->template_name,
            'language' => $template ? $template->language : 'en', // قيمة افتراضية للغة
        ];
    }
}