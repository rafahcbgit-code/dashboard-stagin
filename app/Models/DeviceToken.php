<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'device_token',
        'device_type',
        'device_name',
        'app_version',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeActiveForUser($query, $userId, $organizationId)
    {
        return $query->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('is_active', 1);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId)
            ->where('is_active', 1);
    }
}
