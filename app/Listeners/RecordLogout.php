<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Services\SecurityAuditService;
use Illuminate\Auth\Events\Logout;

class RecordLogout
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function handle(Logout $event): void
    {
        $this->auditService->record(
            SecurityEventType::Logout,
            $event->user?->getAuthIdentifier(),
            request()->ip(),
        );
    }
}
