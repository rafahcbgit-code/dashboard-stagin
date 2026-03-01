<?php

namespace App\Services;

use App\Models\SallaStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Salla Token Management Service
 * 
 * Handles secure token refresh with:
 * - Single-use refresh token enforcement
 * - Concurrency protection via distributed locks
 * - Automatic revocation on reuse attempts
 * - Full compliance with RFC 6819 Section 5.2.2.3
 */
class SallaTokenService
{
    /**
     * Lock timeout in seconds (how long to wait for lock)
     */
    private const LOCK_TIMEOUT = 10;

    /**
     * Lock TTL in seconds (how long lock is held)
     */
    private const LOCK_TTL = 30;

    /**
     * Get a valid access token for the store
     * Automatically refreshes if expired
     * 
     * @param SallaStore $store
     * @return string Valid access token
     * @throws Exception
     */
    public function getValidAccessToken(SallaStore $store): string
    {
        // Check if store is revoked
        if ($store->is_revoked) {
            throw new Exception(
                "Store credentials have been revoked: {$store->revocation_reason}. " .
                "Please reconnect your Salla store from the settings."
            );
        }

        // If access token is still valid, return it
        if (!$store->isAccessTokenExpired()) {
            return $store->access_token;
        }

        // Access token expired, need to refresh
        Log::info('Salla access token expired, initiating refresh', [
            'merchant_id' => $store->merchant_id,
            'organization_id' => $store->organization_id,
            'expires_at' => $store->expires_at,
        ]);

        // Refresh the token and return new access token
        $this->refreshAccessToken($store);
        
        // Reload from database to get fresh token
        $store->refresh();
        
        return $store->access_token;
    }

    /**
     * Refresh access token using refresh token
     * Implements distributed locking to prevent concurrent refreshes
     * 
     * @param SallaStore $store
     * @param bool $force If true, refresh even if token hasn't expired yet (for manual refresh)
     * @return void
     * @throws Exception
     */
    public function refreshAccessToken(SallaStore $store, bool $force = false): void
    {
        $lockKey = "salla:refresh:lock:{$store->merchant_id}:{$store->organization_id}";
        
        // Try to acquire distributed lock
        $lock = Cache::lock($lockKey, self::LOCK_TTL);
        
        try {
            // Block and wait for lock (up to LOCK_TIMEOUT seconds)
            if (!$lock->block(self::LOCK_TIMEOUT)) {
                throw new Exception('Could not acquire lock for token refresh. Please try again.');
            }

            // Double-check: reload store from database inside the lock
            $store->refresh();

            // Check if another process already refreshed (only if not forcing refresh)
            // When $force = true (manual button click), skip this check
            if (!$force && !$store->isAccessTokenExpired()) {
                Log::info('Token was already refreshed by another process', [
                    'merchant_id' => $store->merchant_id,
                ]);
                return;
            }

            // Critical check: ensure refresh token hasn't been used
            if ($store->isRefreshTokenUsed()) {
                $this->handleRefreshTokenReuse($store);
                throw new Exception(
                    'Refresh token has already been used. Your Salla connection has been revoked for security. ' .
                    'Please reconnect your store from settings.'
                );
            }

            // Check if refresh token is expired
            if ($store->isRefreshTokenExpired()) {
                $store->revoke('refresh_token_expired');
                throw new Exception(
                    'Refresh token has expired. Please reconnect your Salla store from settings.'
                );
            }

            // Perform the actual token refresh
            $this->performTokenRefresh($store);

        } finally {
            // Always release the lock
            optional($lock)->release();
        }
    }

    /**
     * Perform the actual HTTP request to Salla to refresh tokens
     * 
     * @param SallaStore $store
     * @return void
     * @throws Exception
     */
    private function performTokenRefresh(SallaStore $store): void
    {
        $clientId = config('salla.client_id');
        $clientSecret = config('salla.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('Salla OAuth configuration is missing.');
        }

        Log::info('Salla: Exchanging refresh token for new access token', [
            'merchant_id' => $store->merchant_id,
            'organization_id' => $store->organization_id,
        ]);

        // **CRITICAL: Mark refresh token as used BEFORE making API call**
        // This prevents race conditions if request is slow or retried
        DB::transaction(function () use ($store) {
            $store->lockForUpdate();
            
            // Double-check inside transaction
            if ($store->isRefreshTokenUsed()) {
                throw new Exception('Refresh token was already used in concurrent request.');
            }
            
            $store->markRefreshTokenAsUsed();
        });

        try {
            // Make the refresh request to Salla
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(1, 500, null, false) // Only 1 retry to avoid multiple uses
                ->post('https://accounts.salla.sa/oauth2/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $store->refresh_token,
                ]);

            if (!$response->successful()) {
                // Refresh failed, revoke everything
                Log::error('Salla: Token refresh failed', [
                    'merchant_id' => $store->merchant_id,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                $store->revoke('refresh_request_failed');

                throw new Exception(
                    'Failed to refresh Salla access token. Please reconnect your store.'
                );
            }

            $tokenData = $response->json() ?? [];

            // Extract tokens from response
            $newAccessToken = data_get($tokenData, 'access_token') 
                ?? data_get($tokenData, 'data.access_token');
            
            $newRefreshToken = data_get($tokenData, 'refresh_token') 
                ?? data_get($tokenData, 'data.refresh_token');
            
            $expiresIn = data_get($tokenData, 'expires_in') 
                ?? data_get($tokenData, 'data.expires_in');

            if (empty($newAccessToken) || empty($newRefreshToken) || !is_numeric($expiresIn)) {
                Log::error('Salla: Invalid refresh response', [
                    'merchant_id' => $store->merchant_id,
                    'has_access_token' => !empty($newAccessToken),
                    'has_refresh_token' => !empty($newRefreshToken),
                    'has_expires_in' => !empty($expiresIn),
                ]);

                $store->revoke('invalid_refresh_response');

                throw new Exception('Invalid token refresh response from Salla.');
            }

            // Calculate expiration times
            $accessTokenExpiresAt = now()->addSeconds((int) $expiresIn);
            $refreshTokenExpiresAt = now()->addDays(30); // Refresh token valid for 30 days

            // **CRITICAL: Update tokens in a transaction**
            DB::transaction(function () use (
                $store,
                $newAccessToken,
                $newRefreshToken,
                $accessTokenExpiresAt,
                $refreshTokenExpiresAt
            ) {
                $store->update([
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'expires_at' => $accessTokenExpiresAt,
                    'refresh_token_expires_at' => $refreshTokenExpiresAt,
                    'refresh_token_used_at' => null, // Reset for new refresh token
                    'last_refresh_at' => now(),
                    'token_version' => $store->token_version + 1,
                ]);
            });

            Log::info('Salla: Token refresh completed successfully', [
                'merchant_id' => $store->merchant_id,
                'new_token_version' => $store->token_version,
                'access_expires_at' => $accessTokenExpiresAt,
                'refresh_expires_at' => $refreshTokenExpiresAt,
            ]);

        } catch (Exception $e) {
            // If anything goes wrong, revert the "used" flag might not be possible
            // So we log and let the store remain in "used" state to prevent further attempts
            Log::error('Salla: Exception during token refresh', [
                'merchant_id' => $store->merchant_id,
                'exception' => $e->getMessage(),
            ]);

            // Revoke to force reconnection
            if (!$store->is_revoked) {
                $store->revoke('exception_during_refresh');
            }

            throw $e;
        }
    }

    /**
     * Handle attempted reuse of refresh token
     * This is a CRITICAL security event indicating:
     * - Potential token theft
     * - Race condition
     * - Implementation bug
     * 
     * Per RFC 6819, we MUST revoke ALL tokens immediately
     * 
     * @param SallaStore $store
     * @return void
     */
    private function handleRefreshTokenReuse(SallaStore $store): void
    {
        Log::critical('SECURITY: Refresh token reuse detected!', [
            'merchant_id' => $store->merchant_id,
            'organization_id' => $store->organization_id,
            'refresh_token_used_at' => $store->refresh_token_used_at,
            'last_refresh_at' => $store->last_refresh_at,
        ]);

        // Immediately revoke all tokens
        $store->revoke('refresh_token_reuse_detected');

        // You might want to:
        // 1. Send alert to admin
        // 2. Log to security monitoring system
        // 3. Notify the merchant
        // 4. Trigger investigation workflow

        // Example: Send notification (implement as needed)
        // Notification::route('mail', config('mail.security_team'))
        //     ->notify(new RefreshTokenReuseDetected($store));
    }

    /**
     * Check if a store's tokens need refreshing soon
     * Useful for proactive refresh before expiration
     * 
     * @param SallaStore $store
     * @param int $bufferMinutes How many minutes before expiry to consider "needs refresh"
     * @return bool
     */
    public function needsRefreshSoon(SallaStore $store, int $bufferMinutes = 60): bool
    {
        if ($store->is_revoked || $store->isRefreshTokenUsed()) {
            return false;
        }

        $expiresAt = $store->expires_at;
        $thresholdTime = now()->addMinutes($bufferMinutes);

        return $expiresAt->lessThanOrEqualTo($thresholdTime);
    }

    /**
     * Validate that a store has valid credentials
     * 
     * @param SallaStore $store
     * @return bool
     */
    public function hasValidCredentials(SallaStore $store): bool
    {
        return $store->isValid() && !$store->isAccessTokenExpired();
    }
}
