<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Services\SecurityAuditService;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function handle(Login $event): void
    {
        $this->auditService->record(
            SecurityEventType::LoginSuccess,
            $event->user->getAuthIdentifier(),
            request()->ip(),
        );
    }
}
