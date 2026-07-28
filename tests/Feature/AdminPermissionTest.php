<?php

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function adminRole(string $name): Role
{
    return Role::create(['uuid' => (string) Str::uuid(), 'name' => $name]);
}

function adminUserWithRole(string $role): User
{
    return User::factory()->create(['role' => $role]);
}

it('denies a direct admin route request when the role has no permission', function () {
    adminRole('Limited staff');
    $user = adminUserWithRole('Limited staff');

    $this->actingAs($user, 'admin')
        ->get('/admin/dashboard')
        ->assertForbidden();
});

it('allows a role to access only its granted admin route', function () {
    $role = adminRole('Dashboard viewer');
    RolePermission::create(['role_id' => $role->id, 'module' => 'dashboard', 'action' => 'view']);
    $user = adminUserWithRole('Dashboard viewer');

    $this->actingAs($user, 'admin')
        ->get('/admin/dashboard')
        ->assertOk();

    $this->actingAs($user, 'admin')
        ->get('/admin/organizations')
        ->assertForbidden();
});

it('keeps the built-in admin role unrestricted', function () {
    $user = adminUserWithRole('admin');

    $this->actingAs($user, 'admin')
        ->get('/admin/dashboard')
        ->assertOk();
});

it('does not allow a role editor to grant permissions it does not have', function () {
    $editorRole = adminRole('Role editor');
    RolePermission::create(['role_id' => $editorRole->id, 'module' => 'roles', 'action' => 'edit']);
    $target = adminRole('Target role');
    $editor = adminUserWithRole('Role editor');

    $this->actingAs($editor, 'admin')
        ->put('/admin/team/roles/' . $target->uuid, [
            'name' => 'Target role',
            'permissions' => ['customers' => ['view' => true]],
        ])
        ->assertForbidden();

    expect(RolePermission::where('role_id', $target->id)->count())->toBe(0);
});
