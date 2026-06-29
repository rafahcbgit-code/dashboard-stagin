<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Support\Facades\Auth;

class CheckOrganizationId
{
    public function handle($request, Closure $next)
    {
        if (!session()->has('current_organization')) {
            $organizationId = $this->restoreOrganizationFromPersistentState($request);

            if (! $organizationId) {
                return redirect()->route('user.organization.index');
            }

            session()->put('current_organization', $organizationId);
            cookie()->queue('last_organization_id', (string) $organizationId, 60 * 24 * 365);
        }

        return $next($request);
    }

    private function restoreOrganizationFromPersistentState($request): ?int
    {
        $user = $request->user('user') ?: Auth::guard('user')->user();

        if (! $user) {
            return null;
        }

        $lastOrganizationId = (int) $request->cookie('last_organization_id');

        if ($lastOrganizationId > 0 && $this->userCanAccessOrganization($user->id, $lastOrganizationId)) {
            return $lastOrganizationId;
        }

        $teams = Team::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('organization')
            ->get(['organization_id']);

        if ($teams->count() === 1) {
            return (int) $teams->first()->organization_id;
        }

        return null;
    }

    private function userCanAccessOrganization(int $userId, int $organizationId): bool
    {
        return Team::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('organization')
            ->exists();
    }
}
