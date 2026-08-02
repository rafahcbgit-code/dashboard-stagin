<?php

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Str;

class AdminPermissionService
{
    public function isSuperAdmin(?User $user): bool
    {
        return $user !== null && strtolower((string) $user->role) === 'admin';
    }

    public function permissionKeys(User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return ['*'];
        }

        return RolePermission::query()
            ->whereHas('role', fn ($query) => $query->where('name', $user->role)->whereNull('deleted_at'))
            ->get(['module', 'action'])
            ->map(fn ($permission) => $permission->module . '.' . $permission->action)
            ->values()
            ->all();
    }

    public function allows(User $user, string $module, string $action): bool
    {
        return $this->isSuperAdmin($user)
            || in_array($module . '.' . $action, $this->permissionKeys($user), true);
    }

    public function landingPath(User $user): string
    {
        $pages = [
            ['dashboard', 'view', '/admin/dashboard'],
            ['organizations', 'view', '/admin/organizations'],
            ['customers', 'view', '/admin/users'],
            ['billing', 'view', '/admin/payment-logs'],
            ['support', 'view', '/admin/support'],
            ['team', 'view', '/admin/team/users'],
            ['roles', 'view', '/admin/team/roles'],
            ['subscription_plans', 'view', '/admin/plans'],
            ['resource_addons', 'view', '/admin/resource-addons'],
            ['otp_config', 'view', '/admin/otp-config'],
            ['addons', 'view', '/admin/addons'],
            ['languages', 'view', '/admin/languages'],
            ['settings', 'view', '/admin/settings'],
            ['logs', 'view', '/admin/user-logs/notifications'],
        ];

        foreach ($pages as [$module, $action, $path]) {
            if ($this->allows($user, $module, $action)) {
                return $path;
            }
        }

        return '/admin/dashboard';
    }

    public function resolveRoutePermission(string $method, string $uri): ?array
    {
        foreach (config('admin_permissions.routes', []) as [$allowedMethod, $pattern, $module, $action]) {
            if ($allowedMethod === $method && Str::is($pattern, $uri)) {
                return compact('module', 'action');
            }
        }

        return null;
    }
}
