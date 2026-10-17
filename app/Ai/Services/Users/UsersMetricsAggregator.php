<?php

namespace App\Ai\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UsersMetricsAggregator
{
    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function total(): array
    {
        return $this->singleValueMetric('Usuarios totales', User::query()->count());
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function active(): array
    {
        return $this->singleValueMetric('Usuarios activos', User::query()->active()->count(), ['status' => 'active']);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function inactive(): array
    {
        return $this->singleValueMetric('Usuarios inactivos', User::query()->inactive()->count(), ['status' => 'inactive']);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function withRoles(): array
    {
        return $this->singleValueMetric('Usuarios con roles', User::query()->has('roles')->count(), ['has_roles' => true]);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function withoutRoles(): array
    {
        return $this->singleValueMetric('Usuarios sin roles', User::query()->doesntHave('roles')->count(), ['has_roles' => false]);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function verified(): array
    {
        return $this->singleValueMetric('Usuarios verificados', User::query()->whereNotNull('email_verified_at')->count(), ['email_verified' => true]);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function unverified(): array
    {
        return $this->singleValueMetric('Usuarios no verificados', User::query()->whereNull('email_verified_at')->count(), ['email_verified' => false]);
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function roleDistribution(): array
    {
        $distribution = $this->roleDistributionRows();

        return [
            'metric' => [
                'label' => 'Roles con usuarios asignados',
                'value' => count($distribution),
                'unit' => 'roles',
            ],
            'breakdown' => $distribution,
            'applied_filters' => null,
        ];
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function mostCommonRole(): array
    {
        $distribution = $this->roleDistributionRows();
        $topRole = $distribution[0] ?? null;

        return [
            'metric' => [
                'label' => $topRole['label'] ?? 'Sin roles asignados',
                'value' => $topRole['value'] ?? 0,
                'unit' => 'users',
            ],
            'breakdown' => $topRole === null ? [] : [$topRole],
            'applied_filters' => null,
        ];
    }

    /**
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    public function administrativeAccess(): array
    {
        return $this->singleValueMetric(
            'Usuarios con acceso administrativo',
            User::query()->administrativeAccess()->count(),
            ['access_profile' => 'administrative_access'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $appliedFilters
     * @return array{metric: array{label: string, value: int, unit: 'users'|'roles'}, breakdown: list<array{key: string, label: string, value: int}>, applied_filters: array<string, mixed>|null}
     */
    protected function singleValueMetric(string $label, int $value, ?array $appliedFilters = null): array
    {
        return [
            'metric' => [
                'label' => $label,
                'value' => $value,
                'unit' => 'users',
            ],
            'breakdown' => [],
            'applied_filters' => $appliedFilters,
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: int}>
     */
    protected function roleDistributionRows(): array
    {
        $userMorph = (new User)->getMorphClass();

        return DB::table('roles')
            ->join('model_has_roles', function ($join) use ($userMorph): void {
                $join->on('roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', '=', $userMorph);
            })
            ->selectRaw('roles.name, roles.display_name, COUNT(model_has_roles.model_id) as assigned_users')
            ->groupBy('roles.name', 'roles.display_name')
            ->orderByDesc('assigned_users')
            ->orderBy('roles.name')
            ->get()
            ->map(fn (object $row): array => [
                'key' => (string) $row->name,
                'label' => (string) ($row->display_name ?: $row->name),
                'value' => (int) $row->assigned_users,
            ])
            ->values()
            ->all();
    }
}
