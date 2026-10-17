<?php

namespace App\Http\Controllers\System;

use App\Actions\System\Users\CreateUserAction;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Users\StoreUserRequest;
use App\Http\Requests\System\Users\UpdateUserRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a paginated list of users with optional search, role, and status filters.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $allowedSorts = ['name', 'email', 'created_at'];
        $sort = in_array((string) $request->input('sort'), $allowedSorts, true)
            ? (string) $request->input('sort')
            : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $users = User::query()
            ->when($request->input('search'), function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $useUnaccent = DB::connection()->getDriverName() === 'pgsql';
                $wrap = fn (string $col) => $useUnaccent
                    ? "unaccent(LOWER({$col})) LIKE unaccent(?)"
                    : "LOWER({$col}) LIKE ?";

                $query->where(fn ($q) => $q
                    ->whereRaw($wrap('name'), [$term])
                    ->orWhereRaw($wrap('email'), [$term])
                );
            })
            ->when($request->input('status'), fn ($query, string $status) => match ($status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => $query,
            })
            ->when($request->input('role'), fn ($query, string $roleId) => match ($roleId) {
                'none' => $query->whereDoesntHave('roles'),
                default => $query->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId)),
            })
            ->with('roles')
            ->withCount('roles')
            ->orderBy($sort, $direction)
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('system/users/index', [
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'role', 'sort', 'direction']),
            'roles' => Role::active()->orderBy('name')->get(),
            'breadcrumbs' => [
                ['title' => 'Usuarios', 'href' => route('system.users.index', absolute: false)],
            ],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('system/users/create', [
            'roles' => Role::active()->orderBy('name')->get(),
            'breadcrumbs' => [
                ['title' => 'Usuarios', 'href' => route('system.users.index', absolute: false)],
                ['title' => 'Crear', 'href' => route('system.users.create', absolute: false)],
            ],
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(StoreUserRequest $request, CreateUserAction $createUserAction): RedirectResponse
    {
        $user = $createUserAction->handle([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_active' => $request->boolean('is_active', true),
            'roles' => $request->validated('roles'),
        ]);

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserCreated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['created_user_id' => $user->id, 'email' => $user->email],
        );

        return to_route('system.users.show', $user)
            ->with('success', __('Usuario creado exitosamente.'));
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): Response
    {
        Gate::authorize('view', $user);

        $user->load(['roles.permissions']);

        $groupedEffectivePermissions = $user->getAllPermissions()
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'group_key' => $permission->groupKey(),
                'roles' => $user->roles
                    ->filter(fn ($role) => $role->is_active && $role->hasPermissionTo($permission))
                    ->mapWithKeys(fn ($role) => [$role->name => $role->display_name ?? $role->name])
                    ->all(),
            ])
            ->groupBy(fn ($p) => $p['group_key'])
            ->map(fn ($perms) => $perms->values());

        return Inertia::render('system/users/show', [
            'user' => $user,
            'groupedEffectivePermissions' => $groupedEffectivePermissions,
            'breadcrumbs' => [
                ['title' => 'Usuarios', 'href' => route('system.users.index', absolute: false)],
                ['title' => $user->name],
            ],
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        $user->load('roles');
        $selectedRoleIds = $user->roles->pluck('id')->toArray();

        return Inertia::render('system/users/edit', [
            'user' => $user,
            'roles' => Role::active()->orderBy('name')->get(),
            'selectedRoleIds' => $selectedRoleIds,
            'breadcrumbs' => [
                ['title' => 'Usuarios', 'href' => route('system.users.index', absolute: false)],
                ['title' => $user->name, 'href' => route('system.users.show', ['user' => $user->id], absolute: false)],
                ['title' => 'Editar'],
            ],
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = [
            'name' => $request->validated('name'),
            'email' => Str::lower($request->validated('email')),
            'is_active' => $request->boolean('is_active', $user->is_active),
        ];

        if ($request->filled('password')) {
            $data['password'] = $request->validated('password');
        }

        $user->update($data);

        if ($request->has('roles')) {
            $user->syncRoles(
                Role::active()->whereIn('id', $request->validated('roles', []))->get()
            );
        }

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserUpdated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['updated_user_id' => $user->id, 'email' => $user->email],
        );

        return to_route('system.users.show', $user)
            ->with('success', __('Usuario actualizado exitosamente.'));
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->id === $request->user()?->id) {
            return back()->with('error', __('No puedes eliminar tu propia cuenta.'));
        }

        if (User::isLastEffectiveAdmin($user)) {
            return back()->with('error', __('No es posible eliminar al último administrador efectivo del sistema.'));
        }

        $user->delete();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::UserDeleted,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['deleted_user_id' => $user->id, 'email' => $user->email],
        );

        return to_route('system.users.index')
            ->with('success', __('Usuario eliminado exitosamente.'));
    }
}
