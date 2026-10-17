<?php

namespace App\Observers;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\SecurityAuditService;

class TwoFactorAuditObserver
{
    public function __construct(private SecurityAuditService $auditService) {}

    public function saved(User $user): void
    {
        if (! $user->wasChanged('two_factor_confirmed_at')) {
            return;
        }

        $previous = $user->getOriginal('two_factor_confirmed_at');
        $current = $user->two_factor_confirmed_at;

        if ($previous === null && $current !== null) {
            $eventType = SecurityEventType::TwoFactorEnabled;
        } elseif ($previous !== null && $current === null) {
            $eventType = SecurityEventType::TwoFactorDisabled;
        } else {
            return;
        }

        $this->auditService->record(
            $eventType,
            $user->id,
            request()->ip(),
        );
    }
}
