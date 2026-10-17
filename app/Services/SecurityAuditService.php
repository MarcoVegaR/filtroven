<?php

namespace App\Services;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class SecurityAuditService
{
    /**
     * Record a security event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        SecurityEventType $eventType,
        ?int $userId,
        ?string $ipAddress,
        array $metadata = [],
    ): SecurityAuditLog {
        $entry = SecurityAuditLog::query()->create([
            'event_type' => $eventType,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'correlation_id' => Context::get('correlation_id'),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        Log::channel('security')->info("[{$eventType->value}]", [
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
        ]);

        return $entry;
    }
}
