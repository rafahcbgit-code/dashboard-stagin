<?php

namespace App\Models;

use App\Models\Organization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IntegrationConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'platform',
        'store_id',
        'store_name',
        'authorization_token',
        'manager_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
        'disconnected_at',
        'connection_status',
        'scopes',
    ];

    protected $hidden = [
        'authorization_token',
        'manager_token',
        'refresh_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'scopes' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function isConnected(): bool
    {
        return $this->connection_status === 'connected'
            && $this->token_expires_at !== null
            && $this->token_expires_at->isFuture();
    }

    public function setAuthorizationTokenAttribute(?string $value): void
    {
        $this->attributes['authorization_token'] = $this->encryptToken($value);
    }

    public function getAuthorizationTokenAttribute(?string $value): ?string
    {
        return $this->decryptToken($value);
    }

    public function setManagerTokenAttribute(?string $value): void
    {
        $this->attributes['manager_token'] = $this->encryptToken($value);
    }

    public function getManagerTokenAttribute(?string $value): ?string
    {
        return $this->decryptToken($value);
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $this->encryptToken($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $this->decryptToken($value);
    }

    protected function encryptToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value);
    }

    protected function decryptToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
