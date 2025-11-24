<?php

namespace App\Services;

use App\Models\OtpConfig;
use App\Http\Resources\OtpConfigResource;
use App\Models\Module;
use App\Models\RolePermission;

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
}