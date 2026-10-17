<?php

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AuditModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use OwenIt\Auditing\Models\Audit;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AuditModulePermissionsSeeder::class);
});

it('redirects guests from the audit index', function () {
    $this->get(route('system.audit.index'))
        ->assertRedirect(route('login'));
});

it('forbids users without audit view permission', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('system.audit.index'))
        ->assertForbidden();
});

it('forbids users without audit view permission from the audit show route', function () {
    $user = User::factory()->withTwoFactor()->create();
    $audit = createModelAudit();

    $this->actingAs($user)
        ->get(route('system.audit.show', ['source' => 'model', 'id' => $audit->id]))
        ->assertForbidden();
});

it('returns the unified audit index with filter options and navigation visibility', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();
    createModelAudit();
    createSecurityAudit();

    $this->actingAs($admin)
        ->get(route('system.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/audit/index')
            ->has('events.data', 2)
            ->has('filterOptions.sources', 3)
            ->where('filters.source', 'all')
            ->where('hasActiveDateFilters', false)
            ->where('events.per_page', 20)
            ->where('ui.navigation.items', fn ($items) => collect($items)->contains(fn (array $item) => $item['title'] === 'Auditoría'))
        );
});

it('marks date-only overrides as active filters in the audit index props', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'to' => now()->subDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/audit/index')
            ->where('hasActiveDateFilters', true)
        );
});

it('exposes localized helper dictionaries for event and auditable type filters', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $options = $this->actingAs($admin)
        ->get(route('system.audit.index', ['source' => 'all']))
        ->assertOk()
        ->inertiaProps('filterOptions');

    $eventLabels = collect($options['events'])->pluck('label', 'value');
    $auditableTypeLabels = collect($options['auditableTypes'])->pluck('label', 'value');

    expect($eventLabels->get('created'))->toBe('Creación')
        ->and($eventLabels->get(SecurityEventType::LoginSuccess->value))->toBe('Inicio de sesión')
        ->and($auditableTypeLabels->get('User'))->toBe('Usuario')
        ->and($auditableTypeLabels->get('Role'))->toBe('Rol')
        ->and($auditableTypeLabels->get('Permission'))->toBe('Permiso');
});

it('filters the unified index by source and keeps security rows out of model results', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $modelAudit = createModelAudit();
    createSecurityAudit();

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', ['source' => 'model']))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('model_'.$modelAudit->id)
        ->and($rows[0]['source'])->toBe('model');
});

it('applies the default 30 day window when no date filters are provided', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();

    $recent = createModelAudit(createdAt: now()->subDays(5));
    createSecurityAudit(occurredAt: now()->subDays(45));

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index'))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('model_'.$recent->id);
});

it('filters unified rows with an explicit from and to range', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();

    createModelAudit(createdAt: now()->startOfDay()->subDays(7));
    $insideRange = createModelAudit(createdAt: now()->startOfDay()->subDays(3));
    createSecurityAudit(occurredAt: now()->startOfDay()->subDay());

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'from' => now()->startOfDay()->subDays(4)->toDateString(),
            'to' => now()->startOfDay()->subDays(2)->toDateString(),
        ]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('model_'.$insideRange->id);
});

it('filters unified rows with only from date', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();

    createModelAudit(createdAt: now()->startOfDay()->subDays(6));
    $fromDate = now()->startOfDay()->subDays(2);
    $insideRange = createSecurityAudit(occurredAt: now()->startOfDay()->subDay());

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'from' => $fromDate->toDateString(),
        ]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('security_'.$insideRange->id)
        ->and($rows[0]['source'])->toBe('security');
});

it('applies auditable filters only to model records', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $subject = User::factory()->create(['name' => 'Filtro entidad']);
    createModelAudit(subject: $subject);
    createSecurityAudit();

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'source' => 'model',
            'auditable_type' => 'User',
            'auditable_id' => $subject->id,
        ]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_type'])->toBe('User')
        ->and($rows[0]['subject_id'])->toBe($subject->id);
});

it('ignores model-only filters when requesting security source rows', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();
    createModelAudit(subject: User::factory()->create());
    $security = createSecurityAudit();

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'source' => 'security',
            'auditable_type' => 'User',
            'auditable_id' => 999999,
        ]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['source'])->toBe('security')
        ->and($rows[0]['source_record_id'])->toBe($security->id);
});

it('filters unified rows by actor user id across model and security sources', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $targetActor = User::factory()->create(['name' => 'Actor objetivo']);
    $otherActor = User::factory()->create(['name' => 'Actor secundario']);

    createModelAudit(actor: $targetActor);
    createModelAudit(actor: $otherActor);
    createSecurityAudit(actor: $targetActor);
    createSecurityAudit(actor: $otherActor);

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', ['user_id' => $targetActor->id]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->every(fn (array $row) => $row['actor_id'] === $targetActor->id))->toBeTrue()
        ->and(collect($rows)->pluck('source')->all())->toContain('model', 'security');
});

it('filters model rows by event value', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    createModelAudit(event: 'created');
    createModelAudit(event: 'updated');

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', ['source' => 'model', 'event' => 'created']))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['source'])->toBe('model')
        ->and($rows[0]['event'])->toBe('created');
});

it('filters security rows by event value', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    createSecurityAudit(eventType: SecurityEventType::LoginSuccess);
    createSecurityAudit(eventType: SecurityEventType::Logout);

    $rows = $this->actingAs($admin)
        ->get(route('system.audit.index', ['source' => 'security', 'event' => SecurityEventType::Logout->value]))
        ->assertOk()
        ->inertiaProps('events.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['source'])->toBe('security')
        ->and($rows[0]['event'])->toBe(SecurityEventType::Logout->value);
});

it('provides row data that navigates to the audit detail route', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $audit = createModelAudit();

    $row = $this->actingAs($admin)
        ->get(route('system.audit.index', ['source' => 'model']))
        ->assertOk()
        ->inertiaProps('events.data.0');

    expect($row['source'])->toBe('model')
        ->and($row['source_record_id'])->toBe($audit->id);

    $this->actingAs($admin)
        ->get(route('system.audit.show', [
            'source' => $row['source'],
            'id' => $row['source_record_id'],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('system/audit/show'));
});

it('resets filter state to defaults when the toolbar clear contract requests source all', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();
    $actor = User::withoutAuditing(fn () => User::factory()->create());
    $fromDate = now()->startOfDay()->subDays(10)->toDateString();
    $toDate = now()->startOfDay()->subDays(3)->toDateString();
    $defaultFromDate = now()->subDays(30)->toDateString();
    $defaultToDate = now()->toDateString();

    createModelAudit(actor: $actor, event: 'created');
    createSecurityAudit();

    $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'source' => 'model',
            'from' => $fromDate,
            'to' => $toDate,
            'event' => 'created',
            'user_id' => $actor->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.source', 'model')
            ->where('filters.from', $fromDate)
            ->where('filters.to', $toDate)
            ->where('filters.event', 'created')
            ->where('filters.user_id', (string) $actor->id)
        );

    $this->actingAs($admin)
        ->get(route('system.audit.index', [
            'source' => 'all',
            'from' => '',
            'to' => '',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.source', 'all')
            ->where('filters.from', $defaultFromDate)
            ->where('filters.to', $defaultToDate)
            ->where('filters.event', null)
            ->where('filters.user_id', null)
            ->has('events.data', 2)
        );
});

it('paginates audit results at twenty rows', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    Audit::query()->delete();
    SecurityAuditLog::query()->delete();

    foreach (range(1, 25) as $index) {
        createModelAudit(createdAt: now()->subMinutes($index));
    }

    $this->actingAs($admin)
        ->get(route('system.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('events.per_page', 20)
            ->where('events.total', 25)
            ->where('events.current_page', 1)
            ->where('events.last_page', 2)
            ->has('events.data', 20)
        );
});

it('returns the model detail page without excluded sensitive fields', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $audit = createModelAudit(withSensitiveValues: true);

    $this->actingAs($admin)
        ->get(route('system.audit.show', ['source' => 'model', 'id' => $audit->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/audit/show')
            ->where('event.source', 'model')
            ->where('event.event_label', 'Actualización')
            ->where('event.old_values.name', 'Antes')
            ->missing('event.old_values.password')
            ->missing('event.new_values.remember_token')
        );
});

it('returns the security detail page with metadata and correlation id', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $log = createSecurityAudit(metadata: ['email_attempted' => 'audit@test.com']);

    $this->actingAs($admin)
        ->get(route('system.audit.show', ['source' => 'security', 'id' => $log->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/audit/show')
            ->where('event.source', 'security')
            ->where('event.event_label', 'Intento de sesión fallido')
            ->where('event.correlation_id', $log->correlation_id)
            ->has('event.metadata', 1)
        );
});

it('returns localized metadata labels for known and unknown security metadata keys', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $log = createSecurityAudit(metadata: [
        'email_attempted' => 'audit@test.com',
        'custom_key' => 'custom-value',
    ]);

    $this->actingAs($admin)
        ->get(route('system.audit.show', ['source' => 'security', 'id' => $log->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('event.metadata', function ($metadata): bool {
                $metadataByKey = collect($metadata)->keyBy('key');

                return $metadataByKey->get('email_attempted')['label'] === 'Email intentado'
                    && $metadataByKey->get('custom_key')['label'] === 'Custom key';
            })
        );
});

it('returns 404 for an invalid audit source', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get('/system/audit/invalid/1')
        ->assertNotFound();
});

it('returns 404 when the requested model audit does not exist', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('system.audit.show', ['source' => 'model', 'id' => 999999]))
        ->assertNotFound();
});

it('resolves export before the show route and forbids users without export permission', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('system.audit.view');

    $this->actingAs($viewer)
        ->get(route('system.audit.export'))
        ->assertForbidden();
});

it('streams a csv export with bom, spanish headers, timestamped filename and active filters', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    createModelAudit(createdAt: now()->subDay());
    createSecurityAudit();

    $response = $this->actingAs($admin)
        ->get(route('system.audit.export', ['source' => 'model']))
        ->assertOk();

    $content = $response->streamedContent();

    expect($response->headers->get('content-disposition'))->toContain('auditoria_')
        ->and($content)->toStartWith("\xEF\xBB\xBF")
        ->and($content)->toContain('Fecha,Fuente,Actor,Evento,Entidad,IP')
        ->and($content)->toContain('Modelos')
        ->and($content)->not->toContain('Seguridad');
});

function createModelAudit(?User $subject = null, ?User $actor = null, $createdAt = null, bool $withSensitiveValues = false, string $event = 'updated'): Audit
{
    $actor ??= User::withoutAuditing(fn () => User::factory()->create(['name' => 'Actor modelo']));
    $subject ??= User::withoutAuditing(fn () => User::factory()->create(['name' => 'Entidad auditada']));
    $createdAt ??= now()->subMinutes(10);

    return Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => $event,
        'auditable_type' => User::class,
        'auditable_id' => $subject->id,
        'old_values' => array_filter([
            'name' => 'Antes',
            'password' => $withSensitiveValues ? 'secret' : null,
        ], fn ($value) => $value !== null),
        'new_values' => array_filter([
            'name' => 'Después',
            'remember_token' => $withSensitiveValues ? 'token' : null,
        ], fn ($value) => $value !== null),
        'url' => '/system/users/'.$subject->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'tags' => 'users',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function createSecurityAudit(?array $metadata = null, $occurredAt = null, ?User $actor = null, SecurityEventType $eventType = SecurityEventType::LoginFailed): SecurityAuditLog
{
    $occurredAt ??= now()->subMinutes(5);

    return SecurityAuditLog::query()->create([
        'event_type' => $eventType,
        'user_id' => $actor?->id,
        'ip_address' => '10.10.10.10',
        'correlation_id' => (string) str()->uuid(),
        'metadata' => $metadata ?? ['email_attempted' => 'alert@test.com'],
        'occurred_at' => $occurredAt,
    ]);
}
