<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    /**
     * Display the authenticated user's roles and effective permissions.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        $user->loadMissing(['roles', 'roles.permissions']);

        $effectivePermissions = $user->getAllPermissions()
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'group_key' => Str::beforeLast($permission->name, '.'),
                'roles' => $user->roles
                    ->filter(fn ($role) => $role->is_active && $role->hasPermissionTo($permission))
                    ->mapWithKeys(fn ($role) => [$role->name => $role->display_name ?? $role->name])
                    ->all(),
            ])
            ->groupBy(fn ($p) => $p['group_key'])
            ->map(fn ($perms) => $perms->values());

        return Inertia::render('settings/access', [
            'roles' => $user->roles,
            'effectivePermissions' => $effectivePermissions,
            'breadcrumbs' => [
                ['title' => 'Acceso', 'href' => route('settings.access', absolute: false)],
            ],
        ]);
    }
}
