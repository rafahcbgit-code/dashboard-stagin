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
        'refresh_token_expires_at',
        'refresh_token_used_at',
        'last_refresh_at',
        'token_version',
        'is_revoked',
        'revoked_at',
        'revocation_reason',
        'scopes'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'refresh_token_used_at' => 'datetime',
        'last_refresh_at' => 'datetime',
        'scopes' => 'array',
        'is_revoked' => 'boolean',
        'revoked_at' => 'datetime',
    ];

    /**
     * Check if access token is expired
     */
    public function isAccessTokenExpired(): bool
    {
        if ($this->is_revoked) {
            return true;
        }
        
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Check if refresh token is expired
     */
    public function isRefreshTokenExpired(): bool
    {
        if ($this->is_revoked) {
            return true;
        }
        
        if (!$this->refresh_token_expires_at) {
            return false;
        }
        
        return now()->greaterThanOrEqualTo($this->refresh_token_expires_at);
    }

    /**
     * Check if refresh token was already used
     */
    public function isRefreshTokenUsed(): bool
    {
        return !is_null($this->refresh_token_used_at);
    }

    /**
     * Check if store credentials are valid
     */
    public function isValid(): bool
    {
        return !$this->is_revoked 
            && !$this->isRefreshTokenExpired()
            && !$this->isRefreshTokenUsed();
    }

    /**
     * Mark refresh token as used
     */
    public function markRefreshTokenAsUsed(): void
    {
        $this->update([
            'refresh_token_used_at' => now(),
        ]);
    }

    /**
     * Revoke all tokens
     */
    public function revoke(string $reason = 'manual_revocation'): void
    {
        $this->update([
            'is_revoked' => true,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    /**
     * Backward compatibility - kept for existing code
     */
    public function isExpired()
    {
        return $this->isAccessTokenExpired();
    }

    /**
     * Get the organization that owns the Salla store
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
