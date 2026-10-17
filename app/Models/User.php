<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\TwoFactorAuthenticatable;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'display_name'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, \OwenIt\Auditing\Auditable, TwoFactorAuthenticatable;

    /**
     * Defense-in-depth: also excluded globally in config/audit.php.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Boot the User model.
     * Register the last-super-admin deletion guard BEFORE trait boot methods
     * so our check fires before Spatie's `bootHasRoles` detaches roles from `model_has_roles`.
     *
     * We override boot() (rather than a trait boot method) to guarantee priority.
     * The check queries the pivot table directly, so it's reliable even if Spatie
     * has already mutated the relationship state.
     */
    protected static function boot(): void
    {
        static::deleting(function (self $user): void {
            if (static::isLastSuperAdmin($user)) {
                throw ValidationException::withMessages([
                    'role' => __('No es posible eliminar al último usuario con el rol super-admin.'),
                ]);
            }
        });

        parent::boot();
    }

    /**
     * Determine whether the given user is the last user holding the super-admin role.
     * Queries the `model_has_roles` pivot directly so this remains reliable even when
     * called from a `deleting` observer (where Spatie may have already detached roles).
     */
    public static function isLastSuperAdmin(self $user): bool
    {
        $superAdminRoleId = DB::table('roles')
            ->where('name', 'super-admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($superAdminRoleId === null) {
            return false;
        }

        $userHasSuperAdmin = DB::table('model_has_roles')
            ->where('role_id', $superAdminRoleId)
            ->where('model_type', (new static)->getMorphClass())
            ->where('model_id', $user->id)
            ->exists();

        if (! $userHasSuperAdmin) {
            return false;
        }

        $totalSuperAdminCount = DB::table('model_has_roles')
            ->where('role_id', $superAdminRoleId)
            ->where('model_type', (new static)->getMorphClass())
            ->count();

        return $totalSuperAdminCount <= 1;
    }

    /**
     * Remove the super-admin role from this user, with a last-admin safeguard.
     * Use this method instead of removeRole('super-admin') directly.
     *
     * @throws ValidationException when attempting to remove the last super-admin
     */
    public function removeSuperAdminRole(): static
    {
        if (static::isLastSuperAdmin($this)) {
            throw ValidationException::withMessages([
                'role' => __('No es posible quitar el rol super-admin al último administrador del sistema.'),
            ]);
        }

        return $this->removeRole('super-admin');
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive users.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope users with effective administrative access.
     *
     * Administrative access means an active user holding at least one active role
     * that grants the minimum admin trio, or the active `super-admin` role.
     */
    public function scopeAdministrativeAccess(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $builder): void {
                $builder
                    ->whereHas('roles', fn (Builder $q) => $q
                        ->where('is_active', true)
                        ->where('name', 'super-admin'))
                    ->orWhereHas('roles', fn (Builder $q) => $q
                        ->where('is_active', true)
                        ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.users.view'))
                        ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.users.assign-role'))
                        ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.roles.view')));
            });
    }

    /**
     * Scope users with the active super-admin role assigned.
     */
    public function scopeWithSuperAdminRole(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q
            ->where('is_active', true)
            ->where('name', 'super-admin'));
    }

    /**
     * Override A: Return all permissions the user has via roles, filtering out
     * permissions from inactive roles.
     *
     * This powers getAllPermissions() → HandleInertiaRequests → auth.permissions
     * frontend filtering. Without this, inactive-role permissions still appear in the UI.
     *
     * @return Collection<int, SpatiePermission>
     */
    public function getPermissionsViaRoles(): Collection
    {
        return $this->loadMissing([
            'roles' => fn ($q) => $q->where('is_active', true),
            'roles.permissions',
        ])
            ->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->sort()
            ->values();
    }

    /**
     * Override B: Check if the user has the given permission via any active role.
     *
     * This powers hasPermissionTo() → Policies → Gate::authorize() backend enforcement.
     * Without this, a user with only inactive roles can still pass permission checks.
     */
    protected function hasPermissionViaRole(SpatiePermission $permission): bool
    {
        return $this->hasRole(
            $permission->roles->filter(fn ($role) => $role->is_active)
        );
    }

    /**
     * Determine if deactivating or deleting the given user would leave the system
     * without any effective admin coverage.
     *
     * An "effective admin" is an active user (other than the target) holding at least
     * one active role that grants system.users.view + system.users.assign-role +
     * system.roles.view (minimum admin trio per PRD-05 §4.9).
     *
     * Keep isLastSuperAdmin() alongside for the boot-level deleting guard.
     */
    public static function isLastEffectiveAdmin(self $user): bool
    {
        $otherAdminExists = static::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q
                ->where('is_active', true)
                ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.users.view'))
                ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.users.assign-role'))
                ->whereHas('permissions', fn (Builder $pq) => $pq->where('name', 'system.roles.view'))
            )
            ->exists();

        return ! $otherAdminExists;
    }
}
