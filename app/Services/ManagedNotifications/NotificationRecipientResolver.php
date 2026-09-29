<?php

namespace App\Services\ManagedNotifications;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationRecipientResolver
{
    /** @return Collection<int, object> */
    public function forOrganization(int $organizationId): Collection
    {
        $owners = DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->where('teams.role', 'owner')
            ->where('teams.status', 'active')
            ->whereNull('teams.deleted_at')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->select('users.id', 'users.email')
            ->get();

        return $owners->unique('id')->values();
    }

    public function isActiveOwner(int $organizationId, int $userId): bool
    {
        return DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->where('teams.user_id', $userId)
            ->where('teams.role', 'owner')
            ->where('teams.status', 'active')
            ->whereNull('teams.deleted_at')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->exists();
    }

    public function isEligibleEmail(int $organizationId, string $email, ?int $userId = null): bool
    {
        $email = strtolower(trim($email));

        return DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->when($userId !== null, fn ($query) => $query->where('teams.user_id', $userId))
            ->where('teams.role', 'owner')
            ->where('teams.status', 'active')
            ->whereNull('teams.deleted_at')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->whereRaw('LOWER(users.email) = ?', [$email])
            ->exists();
    }
}
