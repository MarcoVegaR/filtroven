<?php

namespace App\Http\Controllers\System\Users;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Users\SyncRolesRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\PermissionRegistrar;

class SyncUserRolesController extends Controller
{
    /**
     * Sync the roles for the specified user.
     *
     * Guard: cannot sync own roles (enforced in SyncRolesRequest::authorize).
     */
    public function __invoke(SyncRolesRequest $request, User $user): RedirectResponse
    {
        $newRoles = Role::active()->whereIn('id', $request->validated('roles'))->get();

        $user->syncRoles($newRoles);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(SecurityAuditService::class)->record(
            eventType: SecurityEventType::PermissionsSynced,
            userId: $request->user()?->id,
            ipAddress: $request->ip(),
            metadata: [
                'target_user_id' => $user->id,
                'email' => $user->email,
                'roles' => $newRoles->pluck('name')->all(),
            ],
        );

        return back()->with('success', __('Roles sincronizados exitosamente.'));
    }
}
