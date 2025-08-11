<?php

namespace App\Services;

use App\Http\Resources\RoleResource;
use App\Models\Module;
use App\Models\Role;
use App\Models\RolePermission;
use DB;

class RoleService
{
    /**
     * Get all roles based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $roles = (new Role)->listAll($request->query('search'));

        return RoleResource::collection($roles);
    }

    /**
     * Retrieve a role by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\Role
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($uuid)
    {
        $role = $uuid ? Role::where('uuid', $uuid)->first() : null;

        $modules = Module::all();
        $permissions = [];

        if ($role) {
            $permissions = RolePermission::where('role_id', $role->id)->get();
        }

        return ['role' => $role, 'modules' => $modules, 'permissions' => $permissions];
    }

    /**
     * Store a new role based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        return DB::transaction(function () use ($request) {
            $newRole = Role::create([
                'name' => $request->input('name'),
            ]);

            // Extract and store permissions in the form permissions[module|action]
            $permissions = $request->input('permissions', []);

            $test = [];
            foreach ($permissions as $module => $actions) {
                // Loop through actions for each module
                foreach ($actions as $action => $value) {
                    // If the value is true, store the role permission in the database
                    if ($value) {
                        RolePermission::create([
                            'role_id' => $newRole->id,
                            'module' => $module,
                            'action' => $action,
                        ]);
                    }
                }
            }

            return $newRole;
        });
    }

    /**
     * Update an existing role and its associated permissions.
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Role
     */
    public function update($request, $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $role = Role::where('uuid', $uuid)->firstOrFail();

            $role->update([
                'name' => $request->input('name'),
            ]);

            // Extract and store permissions in the form permissions[module|action]
            $permissions = $request->input('permissions', []);

            // Delete existing permissions
            RolePermission::where('role_id', $role->id)->delete();

            $test = [];
            foreach ($permissions as $module => $actions) {
                // Loop through actions for each module
                foreach ($actions as $action => $value) {
                    // If the value is true, store the role permission in the database
                    if ($value) {
                        RolePermission::create([
                            'role_id' => $role->id,
                            'module' => $module,
                            'action' => $action,
                        ]);
                    }
                }
            }

            $role->touch();

            return $role;
        });
    }

    /**
     * Remove the specified role and its associated permissions.
     *
     * @param string $uuid
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function destroy($request, $uuid)
    {
        $role = Role::where('uuid', $uuid)->firstOrFail();
        $usersToUpdate = User::where('role', $role->name)->get();
        $newRole = Role::where('uuid', $request->input('new_role'))->firstOrFail();

        // Update the role for each user
        foreach ($usersToUpdate as $user) {
            $user->update([
                'role' => $newRole->name, // Specify the new role here
            ]);
        }

        // Delete the role and its associated permissions
        $role->delete();

        // Return a response indicating successful deletion
        return response()->json(['message' => 'Role and associated permissions deleted successfully']);
    }
}