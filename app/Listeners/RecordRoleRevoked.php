<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Services\SecurityAuditService;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\RoleDetachedEvent;

class RecordRoleRevoked
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function handle(RoleDetachedEvent $event): void
    {
        $rolesOrIds = $event->rolesOrIds;

        $roleNames = collect(is_array($rolesOrIds) ? $rolesOrIds : [$rolesOrIds])
            ->map(fn ($role) => $role instanceof RoleContract
                ? $role->name
                : Role::findById($role)?->name ?? (string) $role
            );

        $userId = $event->model->getKey();

        foreach ($roleNames as $roleName) {
            $this->auditService->record(
                SecurityEventType::RoleRevoked,
                $userId,
                request()->ip(),
                ['role' => $roleName, 'revoked_by' => auth()->id()],
            );
        }
    }
}
