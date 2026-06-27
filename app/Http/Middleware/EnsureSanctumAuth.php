<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authenticateBearerToken($request);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid or missing authentication token'
            ], 401);
        }

        auth()->guard('sanctum')->setUser($user);
        
        //  Attach user to request for easy access in controllers
        $request->merge(['auth_user' => $user]);
        
        return $next($request);
    }

    protected function authenticateBearerToken(Request $request)
    {
        $token = $this->getTokenFromRequest($request);

        if (!$this->isValidBearerToken($token)) {
            return null;
        }

        $model = Sanctum::$personalAccessTokenModel;
        $accessToken = $model::findToken($token);

        if (!$this->isValidAccessToken($accessToken)) {
            return null;
        }

        $user = $accessToken->tokenable->withAccessToken($accessToken);

        event(new TokenAuthenticated($accessToken));

        $this->updateLastUsedAtIfDue($accessToken);

        return $user;
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        if (is_callable(Sanctum::$accessTokenRetrievalCallback)) {
            $token = (string) (Sanctum::$accessTokenRetrievalCallback)($request);

            return $token !== '' ? $token : null;
        }

        return $request->bearerToken();
    }

    protected function isValidBearerToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        if (str_contains($token, '|')) {
            [$id, $plainTextToken] = explode('|', $token, 2);

            return ctype_digit($id) && $plainTextToken !== '';
        }

        return true;
    }

    protected function isValidAccessToken($accessToken): bool
    {
        if (!$accessToken || !$accessToken->tokenable) {
            return false;
        }

        if (!in_array(HasApiTokens::class, class_uses_recursive(get_class($accessToken->tokenable)))) {
            return false;
        }

        $expiration = config('sanctum.expiration');

        $isValid = (!$expiration || $accessToken->created_at->gt(now()->subMinutes($expiration)))
            && (!$accessToken->expires_at || !$accessToken->expires_at->isPast());

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
        }

        return $isValid;
    }

    protected function updateLastUsedAtIfDue($accessToken): void
    {
        $intervalMinutes = (int) config('sanctum.last_used_at_update_interval', 10);

        if ($intervalMinutes <= 0) {
            return;
        }

        $cutoff = now()->subMinutes($intervalMinutes);

        if ($accessToken->last_used_at && $accessToken->last_used_at->gt($cutoff)) {
            return;
        }

        $accessToken->newQuery()
            ->whereKey($accessToken->getKey())
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_used_at')
                    ->orWhere('last_used_at', '<=', $cutoff);
            })
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
