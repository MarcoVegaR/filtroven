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

class RoleActivateController extends Controller
{
    /**
     * Activate the specified role.
     */
    public function __invoke(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('activate', $role);

        $role->update(['is_active' => true]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::RoleActivated,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: ['role_id' => $role->id, 'role_name' => $role->name],
        );

        return back()->with('success', __('Rol activado exitosamente.'));
    }
}
