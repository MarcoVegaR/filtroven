<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Services\SecurityAuditService;
use Illuminate\Auth\Events\Failed;

class RecordFailedLogin
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function handle(Failed $event): void
    {
        $this->auditService->record(
            SecurityEventType::LoginFailed,
            null,
            request()->ip(),
            // Never store the password — only record the attempted email
            ['email_attempted' => $event->credentials['email'] ?? null],
        );
    }
}
