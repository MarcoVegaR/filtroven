<?php

namespace App\Ai\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use JsonSerializable;

class CopilotConversationSnapshot implements Arrayable, JsonSerializable
{
    public const VERSION = 2;

    public const RESULT_USER_ID_LIMIT = 8;

    public const FRESHNESS_FRESH = 'fresh';

    public const FRESHNESS_STALE = 'stale';

    public const FRESHNESS_EXPIRED = 'expired';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(protected array $attributes = [])
    {
        $this->attributes = self::normalizeAttributes($attributes);
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function fromDatabase(mixed $snapshot, mixed $version = null, mixed $lastTurnAt = null): self
    {
        $attributes = [];

        if (is_array($snapshot)) {
            $attributes = $snapshot;
        }

        if (is_string($snapshot) && trim($snapshot) !== '') {
            try {
                $decoded = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = [];
            }

            if (is_array($decoded)) {
                $attributes = $decoded;
            }
        }

        $attributes['conversation_state_version'] = is_numeric($version)
            ? (int) $version
            : ($attributes['conversation_state_version'] ?? self::VERSION);

        if ($lastTurnAt !== null) {
            $attributes['last_turn_at'] = $lastTurnAt;
        }

        return new self($attributes);
    }

    public function version(): int
    {
        return (int) $this->attributes['conversation_state_version'];
    }

    public function lastCapabilityKey(): ?string
    {
        return $this->attributes['last_capability_key'];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastFilters(): array
    {
        return $this->attributes['last_filters'];
    }

    /**
     * @return list<int>
     */
    public function lastResultUserIds(): array
    {
        return $this->attributes['last_result_user_ids'];
    }

    public function lastResultCount(): ?int
    {
        return $this->attributes['last_result_count'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastMetricsSnapshot(): ?array
    {
        return $this->attributes['last_metrics_snapshot'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingClarification(): ?array
    {
        return $this->attributes['pending_clarification'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingActionProposal(): ?array
    {
        return $this->attributes['pending_action_proposal'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingCreateUser(): ?array
    {
        return $this->attributes['pending_create_user'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lastSegments(): array
    {
        return $this->attributes['last_segments'];
    }

    /**
     * @return array{type: string, id: int}|null
     */
    public function resolvedEntity(): ?array
    {
        if (! is_string($this->attributes['last_resolved_entity_type']) || ! is_int($this->attributes['last_resolved_entity_id'])) {
            return null;
        }

        return [
            'type' => $this->attributes['last_resolved_entity_type'],
            'id' => $this->attributes['last_resolved_entity_id'],
        ];
    }

    public function hasContext(): bool
    {
        return $this->lastCapabilityKey() !== null
            || $this->lastResultCount() !== null
            || $this->pendingClarification() !== null
            || $this->pendingActionProposal() !== null
            || $this->pendingCreateUser() !== null
            || $this->resolvedEntity() !== null;
    }

    /**
     * Fase 1c: timestamp del ultimo turn sobre el snapshot (fuente de verdad
     * para staleness). Se persiste en columna dedicada pero tambien viaja en
     * los attributes para evaluacion pura.
     */
    public function lastTurnAt(): ?CarbonImmutable
    {
        $raw = $this->attributes['last_turn_at'] ?? null;

        if ($raw === null) {
            return null;
        }

        if ($raw instanceof CarbonImmutable) {
            return $raw;
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fase 1c: freshness tri-valuada. Fuentes de verdad:
     * - fresh: within soft ttl. Continuacion deictica automatica aceptable.
     * - stale: between soft y hard ttl. Requiere confirmacion explicita.
     * - expired: beyond hard ttl. Snapshot se descarta por el planner.
     */
    public function freshness(?CarbonImmutable $now = null): string
    {
        if (! $this->hasContext()) {
            return self::FRESHNESS_FRESH;
        }

        $lastTurn = $this->lastTurnAt();

        if ($lastTurn === null) {
            // Sin timestamp se asume fresh para no romper snapshots legacy;
            // se escribira `last_turn_at` en el proximo write por el
            // CopilotConversationService.
            return self::FRESHNESS_FRESH;
        }

        $now ??= CarbonImmutable::now();
        $softMinutes = (int) config('ai-copilot.snapshot.ttl_soft_minutes', 30);
        $hardHours = (int) config('ai-copilot.snapshot.ttl_hard_hours', 24);

        $minutesSince = max(0, $now->diffInMinutes($lastTurn, true));

        if ($minutesSince <= $softMinutes) {
            return self::FRESHNESS_FRESH;
        }

        if ($minutesSince <= $hardHours * 60) {
            return self::FRESHNESS_STALE;
        }

        return self::FRESHNESS_EXPIRED;
    }

    public function minutesSinceLastTurn(?CarbonImmutable $now = null): ?int
    {
        $lastTurn = $this->lastTurnAt();

        if ($lastTurn === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        return (int) max(0, $now->diffInMinutes($lastTurn, true));
    }

    public function singleResultUserId(): ?int
    {
        $userIds = $this->lastResultUserIds();

        return count($userIds) === 1 ? $userIds[0] : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): self
    {
        return new self([
            ...$this->attributes,
            ...$attributes,
        ]);
    }

    /**
     * @return array{snapshot: string, snapshot_version: int, last_turn_at: string|null}
     */
    public function toDatabase(): array
    {
        $lastTurn = $this->lastTurnAt();

        return [
            'snapshot' => json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'snapshot_version' => $this->version(),
            'last_turn_at' => $lastTurn?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{
     *   last_user_request_normalized: ?string,
     *   last_intent_family: ?string,
     *   last_capability_key: ?string,
     *   last_filters: array<string, mixed>,
     *   last_resolved_entity_type: ?string,
     *   last_resolved_entity_id: ?int,
     *   last_result_user_ids: list<int>,
     *   last_result_count: ?int,
     *   last_metrics_snapshot: array<string, mixed>|null,
     *   pending_clarification: array<string, mixed>|null,
     *   pending_action_proposal: array<string, mixed>|null,
     *   conversation_state_version: int
     * }
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @return array{
     *   last_user_request_normalized: ?string,
     *   last_intent_family: ?string,
     *   last_capability_key: ?string,
     *   last_filters: array<string, mixed>,
     *   last_resolved_entity_type: ?string,
     *   last_resolved_entity_id: ?int,
     *   last_result_user_ids: list<int>,
     *   last_result_count: ?int,
     *   last_metrics_snapshot: array<string, mixed>|null,
     *   pending_clarification: array<string, mixed>|null,
     *   pending_action_proposal: array<string, mixed>|null,
     *   conversation_state_version: int
     * }
     */
    public function jsonSerialize(): array
    {
        $serialized = $this->toArray();
        $lastTurn = $serialized['last_turn_at'] ?? null;

        if ($lastTurn instanceof CarbonImmutable) {
            $serialized['last_turn_at'] = $lastTurn->toIso8601String();
        }

        return $serialized;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *   last_user_request_normalized: ?string,
     *   last_intent_family: ?string,
     *   last_capability_key: ?string,
     *   last_filters: array<string, mixed>,
     *   last_resolved_entity_type: ?string,
     *   last_resolved_entity_id: ?int,
     *   last_result_user_ids: list<int>,
     *   last_result_count: ?int,
     *   last_metrics_snapshot: array<string, mixed>|null,
     *   pending_clarification: array<string, mixed>|null,
     *   pending_action_proposal: array<string, mixed>|null,
     *   conversation_state_version: int
     * }
     */
    protected static function normalizeAttributes(array $attributes): array
    {
        return [
            'last_user_request_normalized' => is_string($attributes['last_user_request_normalized'] ?? null)
                ? $attributes['last_user_request_normalized']
                : null,
            'last_intent_family' => is_string($attributes['last_intent_family'] ?? null)
                ? $attributes['last_intent_family']
                : null,
            'last_capability_key' => is_string($attributes['last_capability_key'] ?? null)
                ? $attributes['last_capability_key']
                : null,
            'last_filters' => is_array($attributes['last_filters'] ?? null)
                ? $attributes['last_filters']
                : [],
            'last_resolved_entity_type' => is_string($attributes['last_resolved_entity_type'] ?? null)
                ? $attributes['last_resolved_entity_type']
                : null,
            'last_resolved_entity_id' => is_numeric($attributes['last_resolved_entity_id'] ?? null)
                ? (int) $attributes['last_resolved_entity_id']
                : null,
            'last_result_user_ids' => array_values(array_map(
                static fn (int|string $userId): int => (int) $userId,
                array_slice(array_filter(
                    is_array($attributes['last_result_user_ids'] ?? null) ? $attributes['last_result_user_ids'] : [],
                    static fn (mixed $userId): bool => is_int($userId) || (is_string($userId) && is_numeric($userId)),
                ), 0, self::RESULT_USER_ID_LIMIT),
            )),
            'last_result_count' => is_numeric($attributes['last_result_count'] ?? null)
                ? (int) $attributes['last_result_count']
                : null,
            'last_metrics_snapshot' => is_array($attributes['last_metrics_snapshot'] ?? null)
                ? $attributes['last_metrics_snapshot']
                : null,
            'pending_clarification' => is_array($attributes['pending_clarification'] ?? null)
                ? $attributes['pending_clarification']
                : null,
            'pending_action_proposal' => is_array($attributes['pending_action_proposal'] ?? null)
                ? self::normalizePendingActionProposal($attributes['pending_action_proposal'])
                : null,
            'pending_create_user' => is_array($attributes['pending_create_user'] ?? null)
                ? $attributes['pending_create_user']
                : null,
            'last_segments' => array_values(array_filter(
                is_array($attributes['last_segments'] ?? null) ? $attributes['last_segments'] : [],
                static fn (mixed $segment): bool => is_array($segment),
            )),
            'conversation_state_version' => is_numeric($attributes['conversation_state_version'] ?? null)
                ? max((int) $attributes['conversation_state_version'], 1)
                : self::VERSION,
            'last_turn_at' => self::normalizeLastTurnAt($attributes['last_turn_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    protected static function normalizePendingActionProposal(array $proposal): array
    {
        return [
            'id' => is_string($proposal['id'] ?? null) ? $proposal['id'] : null,
            'action_type' => is_string($proposal['action_type'] ?? null) ? $proposal['action_type'] : null,
            'target' => is_array($proposal['target'] ?? null) ? $proposal['target'] : null,
            'payload' => is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [],
            'summary' => is_string($proposal['summary'] ?? null) ? $proposal['summary'] : null,
            'required_permissions' => array_values(array_filter(
                is_array($proposal['required_permissions'] ?? null) ? $proposal['required_permissions'] : [],
                static fn (mixed $permission): bool => is_string($permission),
            )),
            'created_at' => is_string($proposal['created_at'] ?? null) ? $proposal['created_at'] : null,
            'expires_at' => is_string($proposal['expires_at'] ?? null) ? $proposal['expires_at'] : null,
            'fingerprint' => is_string($proposal['fingerprint'] ?? null) ? $proposal['fingerprint'] : null,
            'can_execute' => (bool) ($proposal['can_execute'] ?? false),
        ];
    }

    protected static function normalizeLastTurnAt(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
