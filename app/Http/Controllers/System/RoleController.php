<?php

namespace App\Http\Controllers\System;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Roles\StoreRoleRequest;
use App\Http\Requests\System\Roles\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Display a paginated list of roles with optional search and status filter.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $allowedSorts = ['name', 'users_count', 'permissions_count', 'created_at'];
        $sort = in_array((string) $request->input('sort'), $allowedSorts, true)
            ? (string) $request->input('sort')
            : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        $roles = Role::query()
            ->when($request->input('search'), function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $useUnaccent = DB::connection()->getDriverName() === 'pgsql';
                $wrap = fn (string $col) => $useUnaccent
                    ? "unaccent(LOWER({$col})) LIKE unaccent(?)"
                    : "LOWER({$col}) LIKE ?";

                $query->where(fn ($q) => $q
                    ->whereRaw($wrap('name'), [$term])
                    ->orWhereRaw($wrap('display_name'), [$term])
                );
            })
            ->when($request->input('status'), fn ($query, string $status) => match ($status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => $query,
            })
            ->withCount(['permissions', 'users'])
            ->orderBy($sort, $direction)
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('system/roles/index', [
            'roles' => $roles,
            'filters' => $request->only(['search', 'status', 'sort', 'direction']),
            'breadcrumbs' => [
                ['title' => 'Roles', 'href' => route('system.roles.index', absolute: false)],
            ],
        ]);
    }

    /**
     * Show the form for creating a new role.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        $permissions = Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => $permission->groupKey());

        return Inertia::render('system/roles/create', [
            'groupedPermissions' => $permissions,
            'breadcrumbs' => [
                ['title' => 'Roles', 'href' => route('system.roles.index', absolute: false)],
                ['title' => 'Crear', 'href' => route('system.roles.create', absolute: false)],
            ],
        ]);
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'display_name' => $request->validated('display_name'),
            'description' => $request->validated('description'),
            'guard_name' => 'web',
            'is_active' => true,
        ]);

        if ($request->filled('permissions')) {
            $role->syncPermissions(
                Permission::whereIn('id', $request->validated('permissions'))->get()
            );
        }

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::RoleCreated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: [
                'role_id' => $role->id,
                'role_name' => $role->name,
            ],
        );

        return to_route('system.roles.show', $role)
            ->with('success', __('Rol creado exitosamente.'));
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): Response
    {
        Gate::authorize('view', $role);

        $role->load(['permissions', 'users']);
        $role->loadCount('users');

        $groupedPermissions = $role->permissions
            ->groupBy(fn (Permission $permission) => $permission->groupKey());

        return Inertia::render('system/roles/show', [
            'role' => $role,
            'groupedPermissions' => $groupedPermissions,
            'breadcrumbs' => [
                ['title' => 'Roles', 'href' => route('system.roles.index', absolute: false)],
                ['title' => $role->display_name ?? $role->name],
            ],
        ]);
    }

    /**
     * Show the form for editing the specified role.
     */
    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        $allPermissions = Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => $permission->groupKey());

        $selectedPermissionIds = $role->permissions()->pluck('permissions.id')->toArray();

        return Inertia::render('system/roles/edit', [
            'role' => $role,
            'groupedPermissions' => $allPermissions,
            'selectedPermissionIds' => $selectedPermissionIds,
            'breadcrumbs' => [
                ['title' => 'Roles', 'href' => route('system.roles.index', absolute: false)],
                ['title' => $role->display_name ?? $role->name, 'href' => route('system.roles.show', ['role' => $role->id], absolute: false)],
                ['title' => 'Editar'],
            ],
        ]);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $role->update([
            'name' => $request->validated('name'),
            'display_name' => $request->validated('display_name'),
            'description' => $request->validated('description'),
        ]);

        $role->syncPermissions(
            Permission::whereIn('id', $request->validated('permissions', []))->get()
        );

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::RoleUpdated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: [
                'role_id' => $role->id,
                'role_name' => $role->name,
            ],
        );

        return to_route('system.roles.show', $role)
            ->with('success', __('Rol actualizado exitosamente.'));
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        if ($role->users()->count() > 0) {
            return back()->with('error', __('No es posible eliminar un rol que tiene usuarios asignados.'));
        }

        if (Role::isLastAdministrativeCoverageRole($role)) {
            return back()->with('error', __('No es posible eliminar el último rol con cobertura administrativa del sistema.'));
        }

        $deletedRoleId = $role->id;
        $deletedRoleName = $role->name;
        $role->delete();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::RoleDeleted,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: [
                'role_id' => $deletedRoleId,
                'role_name' => $deletedRoleName,
            ],
        );

        return to_route('system.roles.index')
            ->with('success', __('Rol eliminado exitosamente.'));
    }
}
