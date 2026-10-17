<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Services\SecurityAuditService;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\RoleAttachedEvent;

class RecordRoleAssigned
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function handle(RoleAttachedEvent $event): void
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
                SecurityEventType::RoleAssigned,
                $userId,
                request()->ip(),
                ['role' => $roleName, 'assigned_by' => auth()->id()],
            );
        }
    }
}
