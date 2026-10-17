<?php

namespace App\Ai\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CopilotActionProposal
{
    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredPermissions
     * @return array<string, mixed>
     */
    public static function make(
        CopilotActionType $actionType,
        array $target,
        string $summary,
        array $payload,
        bool $canExecute,
        ?string $denyReason,
        array $requiredPermissions,
    ): array {
        $createdAt = CarbonImmutable::now();
        $expiresAt = $createdAt->addMinutes((int) config('ai-copilot.confirmation.ttl_minutes', 15));
        $canonicalPayload = [
            'action_type' => $actionType->value,
            'target' => $target,
            'payload' => $payload,
            'required_permissions' => array_values($requiredPermissions),
        ];

        return [
            'id' => (string) Str::uuid7(),
            'kind' => 'action_proposal',
            'action_type' => $actionType->value,
            'target' => $target,
            'summary' => $summary,
            'payload' => $payload,
            'can_execute' => $canExecute,
            'deny_reason' => $denyReason,
            'required_permissions' => $requiredPermissions,
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'fingerprint' => self::fingerprint($canonicalPayload),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    public static function fingerprint(array $proposal): string
    {
        return hash('sha256', json_encode(self::canonicalize($proposal), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        ksort($value);

        return Arr::map($value, static fn (mixed $item): mixed => self::canonicalize($item));
    }

    /**
     * @return array<string, mixed>
     */
    public static function userTarget(User $user): array
    {
        return [
            'kind' => 'user',
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function newUserTarget(?string $name, ?string $email): array
    {
        return [
            'kind' => 'new_user',
            'name' => $name,
            'email' => $email,
        ];
    }
}
