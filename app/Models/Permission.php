<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class Permission extends \Spatie\Permission\Models\Permission implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['name', 'guard_name', 'display_name', 'description', 'is_active'];

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
     * Scope a query to only include active permissions.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive permissions.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Get the group key: first two segments of the dot-notation name.
     * Example: 'system.roles.view' → 'system.roles'
     */
    public function groupKey(): string
    {
        return Str::beforeLast($this->name, '.');
    }
}
