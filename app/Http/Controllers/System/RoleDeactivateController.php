<?php

namespace App\Http\Controllers\System;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class RoleDeactivateController extends Controller
{
    /**
     * Deactivate the specified role.
     */
    public function __invoke(Request $request, Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin') {
            return back()->with('error', __('El rol super-admin no puede ser desactivado.'));
        }

        if (Role::isLastAdministrativeCoverageRole($role)) {
            return back()->with('error', __('No es posible desactivar el último rol con cobertura administrativa del sistema.'));
        }

        Gate::authorize('deactivate', $role);

        $role->update(['is_active' => false]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::RoleDeactivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['role_id' => $role->id, 'role_name' => $role->name],
        );

        return back()->with('success', __('Rol desactivado exitosamente.'));
    }
}
