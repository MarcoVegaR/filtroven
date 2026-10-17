<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Role extends \Spatie\Permission\Models\Role implements Auditable
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = ['name', 'guard_name', 'display_name', 'description', 'is_active'];

    /**
     * Required permission names for administrative coverage.
     *
     * @var list<string>
     */
    private const ADMINISTRATIVE_COVERAGE_PERMISSIONS = [
        'system.users.view',
        'system.users.assign-role',
        'system.roles.view',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active roles.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive roles.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope alias for active roles — roles that can be assigned to users.
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $this->scopeActive($query);
    }

    /**
     * Determine if deactivating or deleting this role would remove the last
     * active role that grants administrative coverage permissions.
     */
    public static function isLastAdministrativeCoverageRole(self $role): bool
    {
        if (! $role->is_active || ! $role->hasAdministrativeCoveragePermissions()) {
            return false;
        }

        $otherCoverageRoleExists = static::query()
            ->whereKeyNot($role->id)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                foreach (self::ADMINISTRATIVE_COVERAGE_PERMISSIONS as $permission) {
                    $query->whereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery
                        ->where('name', $permission)
                    );
                }
            })
            ->exists();

        return ! $otherCoverageRoleExists;
    }

    /**
     * Determine if this role grants the full administrative coverage set.
     */
    public function hasAdministrativeCoveragePermissions(): bool
    {
        return $this->permissions()
            ->whereIn('name', self::ADMINISTRATIVE_COVERAGE_PERMISSIONS)
            ->count() === count(self::ADMINISTRATIVE_COVERAGE_PERMISSIONS);
    }
}
