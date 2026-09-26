<?php

namespace App\Services\ManagedNotifications;

use Illuminate\Support\Collection;
use Illuminate\Database\Query\Builder;
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

        $admins = DB::table('users')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->where(function ($query) {
                $query->where('users.role', 'admin')
                    ->orWhereIn('users.role', $this->permittedRoles());
            })
            ->select('users.id', 'users.email')
            ->get();

        return $owners->concat($admins)->unique('id')->values();
    }

    public function isEligibleEmail(int $organizationId, string $email): bool
    {
        $email = strtolower(trim($email));

        $ownerExists = DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->where('teams.role', 'owner')
            ->where('teams.status', 'active')
            ->whereNull('teams.deleted_at')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->whereRaw('LOWER(users.email) = ?', [$email])
            ->exists();

        if ($ownerExists) {
            return true;
        }

        return DB::table('users')
            ->whereNull('users.deleted_at')
            ->where('users.status', 1)
            ->whereRaw('LOWER(users.email) = ?', [$email])
            ->where(function ($query) {
                $query->where('users.role', 'admin')
                    ->orWhereIn('users.role', $this->permittedRoles());
            })
            ->exists();
    }

    private function permittedRoles(): Builder
    {
        return DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('role_permissions.module', 'notifications')
            ->where('role_permissions.action', 'receive')
            ->whereNull('roles.deleted_at')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('role_permissions as viewing')
                    ->whereColumn('viewing.role_id', 'roles.id')
                    ->where('viewing.module', 'notifications')
                    ->where('viewing.action', 'view');
            })
            ->select('roles.name');
    }
}
