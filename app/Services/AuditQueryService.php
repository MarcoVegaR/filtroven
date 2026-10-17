<?php

namespace App\Services;

use App\Enums\SecurityEventType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Models\Audit;

class AuditQueryService
{
    private const PER_PAGE = 20;

    private const DEFAULT_WINDOW_DAYS = 30;

    /**
     * @var array<string, string>
     */
    private const MODEL_EVENT_LABELS = [
        'created' => 'Creación',
        'updated' => 'Actualización',
        'deleted' => 'Eliminación',
        'restored' => 'Restauración',
    ];

    /**
     * @var array<string, string>
     */
    private const AUDITABLE_TYPE_LABELS = [
        User::class => 'Usuario',
        Role::class => 'Rol',
        Permission::class => 'Permiso',
        'User' => 'Usuario',
        'Role' => 'Rol',
        'Permission' => 'Permiso',
    ];

    /**
     * @return array{source: string, from: string, to: string, user_id: ?string, event: ?string, auditable_type: ?string, auditable_id: ?string, sort: string, direction: string}
     */
    public function filters(array $input): array
    {
        $source = in_array((string) ($input['source'] ?? 'all'), ['all', 'model', 'security'], true)
            ? (string) ($input['source'] ?? 'all')
            : 'all';

        $from = $this->normalizeDate($input['from'] ?? null)
            ?? $this->defaultFromDate();
        $to = $this->normalizeDate($input['to'] ?? null)
            ?? $this->defaultToDate();

        $sort = in_array((string) ($input['sort'] ?? 'timestamp'), ['timestamp', 'actor_name', 'subject_label', 'ip_address'], true)
            ? (string) ($input['sort'] ?? 'timestamp')
            : 'timestamp';

        $direction = ($input['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return [
            'source' => $source,
            'from' => $from,
            'to' => $to,
            'user_id' => $this->normalizeString($input['user_id'] ?? null),
            'event' => $this->normalizeString($input['event'] ?? null),
            'auditable_type' => $this->normalizeString($input['auditable_type'] ?? null),
            'auditable_id' => $this->normalizeString($input['auditable_id'] ?? null),
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    public function hasActiveDateFilters(array $input): bool
    {
        $filters = $this->filters($input);

        return $filters['from'] !== $this->defaultFromDate()
            || $filters['to'] !== $this->defaultToDate();
    }

    public function paginateIndex(array $input): LengthAwarePaginator
    {
        $filters = $this->filters($input);
        $rows = $this->sortedRows($filters);
        $page = Paginator::resolveCurrentPage();
        $items = $rows
            ->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)
            ->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );

        return $paginator->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function findDetail(string $source, int $id): array
    {
        if ($source === 'model') {
            /** @var Audit $audit */
            $audit = Audit::query()->with(['user', 'auditable'])->findOrFail($id);

            return [
                ...$this->normalizeModelAudit($audit),
                'old_values' => $this->sanitizeAuditValues($audit->old_values),
                'new_values' => $this->sanitizeAuditValues($audit->new_values),
                'url' => $audit->url,
                'user_agent' => $audit->user_agent,
                'tags' => $audit->tags,
            ];
        }

        /** @var SecurityAuditLog $log */
        $log = SecurityAuditLog::query()->with('user')->findOrFail($id);

        return [
            ...$this->normalizeSecurityAudit($log),
            'metadata' => $this->metadataWithLabels($log->metadata ?? []),
            'correlation_id' => $log->correlation_id,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(array $input): Collection
    {
        return $this->sortedRows($this->filters($input));
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(array $input): array
    {
        $filters = $this->filters($input);

        return [
            'sources' => [
                ['value' => 'all', 'label' => 'Todas'],
                ['value' => 'model', 'label' => 'Modelos'],
                ['value' => 'security', 'label' => 'Seguridad'],
            ],
            'events' => $this->eventOptions($filters['source']),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
                ->values()
                ->all(),
            'auditableTypes' => [
                ['value' => 'User', 'label' => 'Usuario'],
                ['value' => 'Role', 'label' => 'Rol'],
                ['value' => 'Permission', 'label' => 'Permiso'],
            ],
            'sortableColumns' => [
                'timestamp',
                'actor_name',
                'subject_label',
                'ip_address',
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sortedRows(array $filters): Collection
    {
        return $this->rows($filters)
            ->sort(function (array $left, array $right) use ($filters): int {
                $leftValue = $this->sortableValue($left, $filters['sort']);
                $rightValue = $this->sortableValue($right, $filters['sort']);

                if ($leftValue === $rightValue) {
                    return strcmp((string) $right['timestamp'], (string) $left['timestamp']);
                }

                return $filters['direction'] === 'asc'
                    ? $leftValue <=> $rightValue
                    : $rightValue <=> $leftValue;
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $filters): Collection
    {
        $rows = collect();

        if (in_array($filters['source'], ['all', 'model'], true)) {
            $rows = $rows->merge($this->modelRows($filters));
        }

        if (in_array($filters['source'], ['all', 'security'], true)) {
            $rows = $rows->merge($this->securityRows($filters));
        }

        return $rows->values();
    }

    private function defaultFromDate(): string
    {
        return now()->subDays(self::DEFAULT_WINDOW_DAYS)->toDateString();
    }

    private function defaultToDate(): string
    {
        return now()->toDateString();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function modelRows(array $filters): Collection
    {
        return Audit::query()
            ->with(['user', 'auditable'])
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['user_id'], fn ($query, string $userId) => $query->where('user_id', (int) $userId))
            ->when($filters['event'], fn ($query, string $event) => $query->where('event', $event))
            ->when($filters['auditable_type'], function ($query, string $type): void {
                $mapped = $this->normalizeAuditableType($type);

                if ($mapped !== null) {
                    $query->where('auditable_type', $mapped);
                }
            })
            ->when($filters['auditable_id'], fn ($query, string $id) => $query->where('auditable_id', (int) $id))
            ->get()
            ->map(fn (Audit $audit) => $this->normalizeModelAudit($audit))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function securityRows(array $filters): Collection
    {
        return SecurityAuditLog::query()
            ->with('user')
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('occurred_at', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('occurred_at', '<=', $to))
            ->when($filters['user_id'], fn ($query, string $userId) => $query->where('user_id', (int) $userId))
            ->when($filters['event'], function ($query, string $event): void {
                $query->where('event_type', $event);
            })
            ->get()
            ->map(fn (SecurityAuditLog $log) => $this->normalizeSecurityAudit($log))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeModelAudit(Audit $audit): array
    {
        $subjectType = $this->auditableTypeAlias($audit->auditable_type);
        $subjectId = $audit->auditable_id === null ? null : (int) $audit->auditable_id;
        $subjectLabel = $this->resolveModelSubjectLabel($audit->auditable_type, $subjectId, $audit->auditable);

        return [
            'id' => 'model_'.$audit->id,
            'source' => 'model',
            'source_record_id' => $audit->id,
            'timestamp' => $audit->created_at?->toIso8601String(),
            'actor_name' => $audit->user?->name,
            'actor_id' => $audit->user?->id,
            'actor_href' => $audit->user?->id !== null
                ? route('system.users.show', ['user' => $audit->user->id], absolute: false)
                : null,
            'event' => $audit->event,
            'event_label' => $this->modelEventLabel($audit->event),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'subject_href' => $this->resolveSubjectHref($audit->auditable_type, $subjectId),
            'ip_address' => $audit->ip_address !== null ? (string) $audit->ip_address : null,
            'source_label' => 'Modelos',
            'source_badge_variant' => 'model',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSecurityAudit(SecurityAuditLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $subjectContext = $this->resolveSecuritySubjectContext($metadata);
        $eventType = $log->event_type instanceof SecurityEventType
            ? $log->event_type
            : SecurityEventType::from((string) $log->event_type);

        return [
            'id' => 'security_'.$log->id,
            'source' => 'security',
            'source_record_id' => $log->id,
            'timestamp' => $log->occurred_at?->toIso8601String(),
            'actor_name' => $log->user?->name,
            'actor_id' => $log->user?->id,
            'actor_href' => $log->user?->id !== null
                ? route('system.users.show', ['user' => $log->user->id], absolute: false)
                : null,
            'event' => $eventType->value,
            'event_label' => $eventType->label(),
            'subject_type' => $subjectContext['type'],
            'subject_id' => $subjectContext['id'],
            'subject_label' => $subjectContext['label'],
            'subject_href' => $subjectContext['href'],
            'ip_address' => $log->ip_address !== null ? (string) $log->ip_address : null,
            'source_label' => 'Seguridad',
            'source_badge_variant' => 'security',
            'metadata' => $this->metadataWithLabels($metadata),
            'correlation_id' => $log->correlation_id,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function eventOptions(string $source): array
    {
        $options = [];

        if (in_array($source, ['all', 'model'], true)) {
            foreach (self::MODEL_EVENT_LABELS as $value => $label) {
                $options[] = ['value' => $value, 'label' => $label];
            }
        }

        if (in_array($source, ['all', 'security'], true)) {
            foreach (SecurityEventType::cases() as $case) {
                $options[] = ['value' => $case->value, 'label' => $case->label()];
            }
        }

        return collect($options)
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->all();
    }

    private function sortableValue(array $row, string $sort): string
    {
        $value = $row[$sort] ?? '';

        return mb_strtolower((string) ($value ?? ''));
    }

    private function modelEventLabel(string $event): string
    {
        return self::MODEL_EVENT_LABELS[$event] ?? ucfirst($event);
    }

    private function normalizeAuditableType(?string $type): ?string
    {
        return match ($type) {
            'User', User::class => User::class,
            'Role', Role::class => Role::class,
            'Permission', Permission::class => Permission::class,
            default => null,
        };
    }

    private function auditableTypeAlias(?string $type): ?string
    {
        return match ($type) {
            User::class, 'User' => 'User',
            Role::class, 'Role' => 'Role',
            Permission::class, 'Permission' => 'Permission',
            default => $type,
        };
    }

    private function resolveModelSubjectLabel(?string $type, ?int $id, mixed $subject): ?string
    {
        if ($subject instanceof User) {
            return $subject->name;
        }

        if ($subject instanceof Role) {
            return $subject->display_name ?? $subject->name;
        }

        if ($subject instanceof Permission) {
            return $subject->display_name ?? $subject->name;
        }

        if ($type === null || $id === null) {
            return null;
        }

        $label = self::AUDITABLE_TYPE_LABELS[$type] ?? class_basename($type);

        return sprintf('%s #%d', $label, $id);
    }

    private function resolveSubjectHref(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        return match ($this->normalizeAuditableType($type)) {
            User::class => route('system.users.show', ['user' => $id], absolute: false),
            Role::class => route('system.roles.show', ['role' => $id], absolute: false),
            Permission::class => route('system.permissions.show', ['permission' => $id], absolute: false),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{type: ?string, id: ?int, label: ?string, href: ?string}
     */
    private function resolveSecuritySubjectContext(array $metadata): array
    {
        $userId = $this->extractNumericMetadata($metadata, ['created_user_id', 'updated_user_id', 'deleted_user_id']);
        $roleId = $this->extractNumericMetadata($metadata, ['role_id']);

        if ($userId !== null) {
            return [
                'type' => 'User',
                'id' => $userId,
                'label' => 'Usuario #'.$userId,
                'href' => route('system.users.show', ['user' => $userId], absolute: false),
            ];
        }

        if ($roleId !== null) {
            return [
                'type' => 'Role',
                'id' => $roleId,
                'label' => isset($metadata['role']) ? 'Rol: '.$metadata['role'] : 'Rol #'.$roleId,
                'href' => route('system.roles.show', ['role' => $roleId], absolute: false),
            ];
        }

        if (isset($metadata['role']) && is_string($metadata['role'])) {
            return [
                'type' => 'Role',
                'id' => null,
                'label' => 'Rol: '.$metadata['role'],
                'href' => null,
            ];
        }

        foreach (['email', 'email_attempted'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                return [
                    'type' => null,
                    'id' => null,
                    'label' => $metadata[$key],
                    'href' => null,
                ];
            }
        }

        return [
            'type' => null,
            'id' => null,
            'label' => null,
            'href' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    private function sanitizeAuditValues(?array $values): array
    {
        $sanitized = collect($values ?? [])
            ->except(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])
            ->all();

        return $this->normalizeNestedValues($sanitized);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{key: string, label: string, value: mixed}>
     */
    private function metadataWithLabels(array $metadata): array
    {
        return collect($metadata)
            ->map(fn (mixed $value, string $key) => [
                'key' => $key,
                'label' => match ($key) {
                    'email_attempted' => 'Email intentado',
                    'email' => 'Email',
                    'role' => 'Rol',
                    'assigned_by' => 'Asignado por',
                    'revoked_by' => 'Revocado por',
                    'created_user_id' => 'Usuario creado',
                    'updated_user_id' => 'Usuario actualizado',
                    'deleted_user_id' => 'Usuario eliminado',
                    'role_id' => 'ID del rol',
                    default => ucfirst(str_replace('_', ' ', $key)),
                },
                'value' => $this->normalizeNestedValue($value),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeNestedValues(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value) => $this->normalizeNestedValue($value))
            ->all();
    }

    private function normalizeNestedValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $nested) => $this->normalizeNestedValue($nested))
                ->all();
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        return $value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $keys
     */
    private function extractNumericMetadata(array $metadata, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return (int) $metadata[$key];
            }
        }

        return null;
    }
}
