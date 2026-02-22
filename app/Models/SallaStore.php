<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaStore extends Model
{
    use HasFactory;
      protected $fillable = [
        'merchant_id',
        'merchant_name',
        'access_token',
        'refresh_token',
        'organization_id',
        'expires_at',
        'scopes'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'scopes' => 'array',
    ];

    public function isExpired()
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Get the organization that owns the Salla store
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
