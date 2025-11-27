<?php

namespace App\Services;

use App\Http\Resources\OtpConfigResource;
use App\Models\Module;
use App\Models\OtpCode;
use App\Models\OtpConfig;
use App\Models\Organization;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyCodeMail;
use App\Models\User;

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
        'uuid' => $data['uuid'] ?? null,
    ];
    
    $config = OtpConfig::firstOrNew($match);

    $config->fill([
        'template_name' => $data['template_name'] ?? $config->template_name,
        'contact_phone' => $data['contact_phone'] ?? $config->contact_phone,
        'calendly_url' => $data['calendly_url'] ?? $config->calendly_url,
        'uuid' => $data['uuid'], 
    ]);

    $config->save();

    return $config;
}

    public function generateAndSaveOtp(string $phone, int $length = 6, int $ttlMinutes = 5, int $user_id = 0): string
    {
        OtpCode::where('user_id', auth()->user()->id)->delete();

        $max = (int) str_repeat('9', $length);
        $otpCode = str_pad(rand(0, $max), $length, '0', STR_PAD_LEFT);
        
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

         OtpCode::create([
            'phone' => $phone,
            'code' => $otpCode,
            'expires_at' => $expiresAt,
            'user_id' => $user_id
        ]);

        return $otpCode;
    }

    public function generateAndSaveOtpForEmail(string $email, int $length = 6, int $ttlMinutes = 5): string
{
    OtpCode::where('user_id', auth()->user()->id)->delete();
    $user = User::where('email', $email)->latest()->first();
    $max = (int) str_repeat('9', $length);
    $otpCode = str_pad(rand(0, $max), $length, '0', STR_PAD_LEFT);
    
    $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

    OtpCode::create([
        'email' => $email, 
        'code' => $otpCode,
        'expires_at' => $expiresAt,
        'user_id' => $user->id
    ]);

     Mail::to($user->email)->send(new VerifyCodeMail($user, $otpCode));

    return $otpCode;
}
    
    
    public function getSenderConfig(): ?array
    {
        $orgConfig = OtpConfig::first();

        if (!$orgConfig) {
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
            'language' => $template ? $template->language : 'en', 
        ];
    }
}