<?php

namespace App\Services;

use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoleService
{
    public function get(object $request)
    {
        $roles = Role::query()
            ->whereNull('deleted_at')
            ->when(! $this->isSuperAdmin(), fn ($query) => $query->where('name', '!=', 'admin'))
            ->where('name', 'like', '%' . $request->query('search') . '%')
            ->latest()
            ->paginate(10);

        return RoleResource::collection($roles);
    }

    public function getByUuid($uuid)
    {
        $role = $uuid ? Role::where('uuid', $uuid)->first() : null;
        $this->assertManageable($role);

        return [
            'role' => $role,
            // The form must use the same source of truth as syncPermissions().
            // Reading every historical row from the modules table can expose
            // actions which are no longer valid in the RBAC catalogue and
            // makes a subsequent save fail with "Invalid permission.".
            'modules' => collect(config('admin_permissions.modules', []))
                ->map(fn (array $actions, string $name) => (object) [
                    'name' => $name,
                    'actions' => implode(',', $actions),
                ])
                ->values(),
            'permissions' => $role ? $role->permissions()->get() : [],
        ];
    }

    public function store(object $request)
    {
        return DB::transaction(function () use ($request) {
            $this->assertRoleNameManageable($request->input('name'));
            $role = Role::create(['name' => $request->input('name')]);
            $this->syncPermissions($role, $request->input('permissions', []));

            return $role;
        });
    }

    public function update(object $request, string $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $role = Role::where('uuid', $uuid)->firstOrFail();
            $this->assertManageable($role);
            $this->assertRoleNameManageable($request->input('name'));

            $role->update(['name' => $request->input('name')]);
            $this->syncPermissions($role, $request->input('permissions', []));
            $role->touch();

            return $role;
        });
    }

    public function destroy(object $request, string $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $role = Role::where('uuid', $uuid)->firstOrFail();
            $replacement = Role::where('uuid', $request->input('new_role'))->firstOrFail();
            $this->assertManageable($role);
            $this->assertManageable($replacement);
            $this->assertAssignable($replacement);

            User::where('role', $role->name)->update(['role' => $replacement->name]);
            $role->delete();

            return response()->json(['message' => 'Role and associated permissions deleted successfully']);
        });
    }

    private function syncPermissions(Role $role, array $permissions): void
    {
        $selected = [];
        $catalogue = config('admin_permissions.modules', []);

        foreach ($permissions as $module => $actions) {
            foreach ((array) $actions as $action => $value) {
                if (! $value) {
                    continue;
                }

                abort_unless(isset($catalogue[$module]) && in_array($action, $catalogue[$module], true), 422, 'Invalid permission.');
                $selected[] = $module . '.' . $action;
            }
        }

        if (! $this->isSuperAdmin()) {
            abort_unless(
                empty(array_diff($selected, $this->permissions()->permissionKeys(auth('admin')->user()))),
                403,
                'You cannot grant permissions you do not have.'
            );
        }

        $role->permissions()->delete();
        foreach ($selected as $permission) {
            [$module, $action] = explode('.', $permission, 2);
            $role->permissions()->create(compact('module', 'action'));
        }
    }

    private function assertManageable(?Role $role): void
    {
        if ($role && strtolower($role->name) === 'admin' && ! $this->isSuperAdmin()) {
            abort(403, 'The admin role can only be managed by an admin.');
        }
    }

    private function assertRoleNameManageable(?string $name): void
    {
        if (strtolower((string) $name) === 'admin' && ! $this->isSuperAdmin()) {
            abort(403, 'The admin role can only be managed by an admin.');
        }
    }

    private function assertAssignable(Role $role): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $rolePermissions = $role->permissions()->get()
            ->map(fn ($permission) => $permission->module . '.' . $permission->action)
            ->all();

        abort_unless(
            empty(array_diff($rolePermissions, $this->permissions()->permissionKeys(auth('admin')->user()))),
            403,
            'You cannot assign a role with permissions you do not have.'
        );
    }

    private function isSuperAdmin(): bool
    {
        return $this->permissions()->isSuperAdmin(auth('admin')->user());
    }

    private function permissions(): AdminPermissionService
    {
        return app(AdminPermissionService::class);
    }
}
