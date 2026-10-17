<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    /**
     * Display a paginated list of permissions grouped by context prefix.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Permission::class);

        $permissions = Permission::query()
            ->when($request->input('search'), fn ($query, string $search) => $query
                ->where(fn ($q) => $q
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('display_name', 'ilike', "%{$search}%")
                )
            )
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => $permission->groupKey());

        return Inertia::render('system/permissions/index', [
            'groupedPermissions' => $permissions,
            'filters' => $request->only(['search']),
            'breadcrumbs' => [
                ['title' => 'Permisos', 'href' => route('system.permissions.index', absolute: false)],
            ],
        ]);
    }

    /**
     * Display the specified permission.
     */
    public function show(Permission $permission): Response
    {
        Gate::authorize('view', $permission);

        $permission->load('roles');

        return Inertia::render('system/permissions/show', [
            'permission' => $permission,
            'breadcrumbs' => [
                ['title' => 'Permisos', 'href' => route('system.permissions.index', absolute: false)],
                ['title' => $permission->display_name ?? $permission->name],
            ],
        ]);
    }
}
