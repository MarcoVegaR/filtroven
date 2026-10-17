<?php

use App\Enums\SecurityEventType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\AuditQueryService;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AuditModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AuditModulePermissionsSeeder::class);
});

it('normalizes filters with the default audit window and sort contract', function () {
    $filters = app(AuditQueryService::class)->filters([]);

    expect($filters['source'])->toBe('all')
        ->and($filters['sort'])->toBe('timestamp')
        ->and($filters['direction'])->toBe('desc')
        ->and($filters['from'])->not->toBeEmpty()
        ->and($filters['to'])->toBe(now()->toDateString());
});

it('returns model audit detail without exposing excluded sensitive fields', function () {
    $actor = User::factory()->create(['name' => 'Audit Actor']);
    $subject = User::factory()->create(['name' => 'Audit Subject']);

    $audit = Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $subject->id,
        'old_values' => ['name' => 'Anterior', 'password' => 'secreto'],
        'new_values' => ['name' => 'Nuevo', 'remember_token' => 'token'],
        'url' => '/system/users/'.$subject->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'tags' => 'users',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $detail = app(AuditQueryService::class)->findDetail('model', $audit->id);

    expect($detail['source'])->toBe('model')
        ->and($detail['event_label'])->toBe('Actualización')
        ->and($detail['subject_label'])->toBe('Audit Subject')
        ->and($detail['old_values'])->toHaveKey('name')
        ->and($detail['old_values'])->not->toHaveKey('password')
        ->and($detail['new_values'])->not->toHaveKey('remember_token');
});

it('returns unified export rows sorted by the requested dimension', function () {
    $actor = User::factory()->create(['name' => 'Zoé']);
    $subject = Role::factory()->create(['name' => 'audited-role', 'display_name' => 'Rol auditado']);

    Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => Role::class,
        'auditable_id' => $subject->id,
        'old_values' => ['display_name' => 'Antes'],
        'new_values' => ['display_name' => 'Después'],
        'ip_address' => '10.0.0.2',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    SecurityAuditLog::query()->create([
        'event_type' => SecurityEventType::LoginFailed,
        'user_id' => null,
        'ip_address' => '10.0.0.1',
        'metadata' => ['email_attempted' => 'demo@example.com'],
        'occurred_at' => now(),
    ]);

    $rows = app(AuditQueryService::class)->exportRows([
        'sort' => 'ip_address',
        'direction' => 'asc',
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('ip_address')->all())->toBe(['10.0.0.1', '10.0.0.2'])
        ->and($rows->pluck('source')->all())->toContain('model', 'security');
});

it('filters model audits by auditable type and id', function () {
    $actor = User::factory()->create();
    $userSubject = User::factory()->create();
    $permissionSubject = Permission::query()->firstOrCreate([
        'name' => 'audit-filter-permission',
        'guard_name' => 'web',
    ], [
        'display_name' => 'Filtro de auditoría',
        'is_active' => true,
    ]);

    Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $userSubject->id,
        'old_values' => ['name' => 'Antes'],
        'new_values' => ['name' => 'Después'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => Permission::class,
        'auditable_id' => $permissionSubject->id,
        'old_values' => ['display_name' => 'Antes'],
        'new_values' => ['display_name' => 'Después'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = app(AuditQueryService::class)->exportRows([
        'source' => 'model',
        'auditable_type' => 'User',
        'auditable_id' => (string) $userSubject->id,
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['subject_type'])->toBe('User')
        ->and($rows->first()['subject_id'])->toBe($userSubject->id);
});

it('builds source-aware event filter options for the index toolbar', function () {
    $service = app(AuditQueryService::class);

    $modelOnly = collect($service->filterOptions(['source' => 'model'])['events'])->pluck('value');
    $securityOnly = collect($service->filterOptions(['source' => 'security'])['events'])->pluck('value');
    $allSources = collect($service->filterOptions(['source' => 'all'])['events'])->pluck('value');

    expect($modelOnly)->toContain('created')
        ->and($modelOnly)->not->toContain(SecurityEventType::LoginSuccess->value)
        ->and($securityOnly)->toContain(SecurityEventType::LoginSuccess->value)
        ->and($securityOnly)->not->toContain('created')
        ->and($allSources)->toContain('created')
        ->and($allSources)->toContain(SecurityEventType::LoginSuccess->value);
});
