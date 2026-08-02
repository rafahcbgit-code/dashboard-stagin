<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;

class AuthorizeAdminPermission
{
    public function __construct(private readonly AdminPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('admin');

        if ($this->permissions->isSuperAdmin($user)) {
            return $next($request);
        }

        $permission = $this->permissions->resolveRoutePermission($request->method(), $request->route()->uri());

        abort_unless(
            $permission && $this->permissions->allows($user, $permission['module'], $permission['action']),
            403,
            'You do not have permission to perform this action.'
        );

        return $next($request);
    }
}
