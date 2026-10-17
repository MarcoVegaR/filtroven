<?php

use App\Ai\Agents\System\UsersGeminiCopilotAgent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);

    config(['ai-copilot.enabled' => true]);
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');
});

function promptBatteryOperator(): User
{
    $role = Role::factory()->active()->create([
        'name' => 'copilot-operator',
        'display_name' => 'Copilot Operator',
    ]);
    $role->syncPermissions(['system.users.view', 'system.users-copilot.view']);

    $user = User::factory()->active()->create([
        'name' => 'Copilot Operator',
        'email' => 'operator@example.com',
    ]);
    $user->assignRole($role);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function seedPromptBatteryFixtures(): array
{
    $admin = Role::query()->firstOrCreate(
        ['name' => 'admin', 'guard_name' => 'web'],
        ['display_name' => 'Admin', 'is_active' => true],
    );
    $admin->forceFill(['display_name' => 'Admin', 'is_active' => true])->save();
    $admin->syncPermissions(['system.users.view', 'system.users.assign-role', 'system.roles.view']);

    $superAdmin = Role::query()->firstOrCreate(
        ['name' => 'super-admin', 'guard_name' => 'web'],
        ['display_name' => 'Super Administrador', 'is_active' => true],
    );
    $superAdmin->forceFill(['display_name' => 'Super Administrador', 'is_active' => true])->save();
    $superAdmin->syncPermissions(['system.users.view', 'system.users.assign-role', 'system.roles.view']);

    $support = Role::factory()->active()->create(['name' => 'support', 'display_name' => 'Soporte']);
    $support->syncPermissions(['system.users.view', 'system.users.update']);

    $auditor = Role::factory()->active()->create(['name' => 'auditor', 'display_name' => 'Auditor']);

    $aliceAdmin = User::factory()->active()->create([
        'name' => 'Alice Admin',
        'email' => 'alice.admin@example.com',
    ]);
    $aliceAdmin->assignRole($admin);

    $ianAdmin = User::factory()->inactive()->create([
        'name' => 'Ian Admin',
        'email' => 'ian.admin@example.com',
    ]);
    $ianAdmin->assignRole($admin);

    $martaAdmin = User::factory()->active()->create([
        'name' => 'Marta Admin',
        'email' => 'marta.admin@example.com',
    ]);
    $martaAdmin->assignRole($admin);

    $sandraSuperAdmin = User::factory()->active()->create([
        'name' => 'Sandra Super Admin',
        'email' => 'sandra.super-admin@example.com',
    ]);
    $sandraSuperAdmin->assignRole($superAdmin);

    $saraSupport = User::factory()->active()->unverified()->withTwoFactor()->create([
        'name' => 'Sara Support',
        'email' => 'sara.support@example.com',
    ]);
    $saraSupport->assignRole($support);

    $lauraAuditor = User::factory()->inactive()->unverified()->create([
        'name' => 'Laura Auditor',
        'email' => 'laura.auditor@example.com',
    ]);
    $lauraAuditor->assignRole($auditor);

    $daniel = User::factory()->active()->create([
        'name' => 'Daniel Query Unique',
        'email' => 'daniel.query@example.com',
    ]);

    $marioVega = User::factory()->active()->create([
        'name' => 'Mario Vega',
        'email' => 'mario.vega@example.com',
    ]);

    $marioSoto = User::factory()->inactive()->create([
        'name' => 'Mario Soto',
        'email' => 'mario.soto@example.com',
    ]);

    return compact(
        'admin',
        'superAdmin',
        'support',
        'auditor',
        'aliceAdmin',
        'ianAdmin',
        'martaAdmin',
        'sandraSuperAdmin',
        'saraSupport',
        'lauraAuditor',
        'daniel',
        'marioVega',
        'marioSoto',
    );
}

function fakeGeminiPromptBattery(): void
{
    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();
}

it('answers total user prompts with deterministic totals', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedTotal = User::query()->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Cuantos usuarios hay',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.total')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedTotal)
        ->assertJsonPath('response.answer', "Hay {$expectedTotal} usuarios en total.");
});

it('answers active user prompts with deterministic totals', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedActive = User::query()->where('is_active', true)->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Cuantos usuarios activos hay',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedActive)
        ->assertJsonPath('response.answer', "Hay {$expectedActive} usuarios activos.");
});

it('answers inactive user prompts with deterministic totals', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedInactive = User::query()->where('is_active', false)->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Cuantos usuarios inactivos hay',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.inactive')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedInactive)
        ->assertJsonPath('response.answer', "Hay {$expectedInactive} usuarios inactivos.");
});

it('answers combined aggregate prompts with the full deterministic set', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedTotal = User::query()->count();
    $expectedActive = User::query()->where('is_active', true)->count();
    $expectedInactive = User::query()->where('is_active', false)->count();
    $mostCommonRole = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->selectRaw('COALESCE(NULLIF(roles.display_name, \'\'), roles.name) as label, count(*) as aggregate')
        ->where('model_type', User::class)
        ->groupBy('roles.id', 'roles.display_name', 'roles.name')
        ->orderByDesc('aggregate')
        ->orderBy('label')
        ->first();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'cuantos usuarios hay , dame los activos e inactivos y dime cual es el rol mas comun',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.combined')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedTotal)
        ->assertJsonPath('response.cards.0.data.breakdown.active', $expectedActive)
        ->assertJsonPath('response.cards.0.data.breakdown.inactive', $expectedInactive)
        ->assertJsonPath('response.cards.0.data.breakdown.most_common_role', $mostCommonRole?->label)
        ->assertJsonPath('response.cards.0.data.breakdown.most_common_role_count', (int) ($mostCommonRole?->aggregate ?? 0));

    expect($response->json('response.answer'))
        ->toContain("Hay {$expectedTotal} usuarios en total.")
        ->toContain("{$expectedActive} activos y {$expectedInactive} inactivos.")
        ->toContain("El rol mas comun es {$mostCommonRole->label} con {$mostCommonRole->aggregate} usuarios asignados.");
});

it('answers combined aggregate prompts that also ask for admin count', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedTotal = User::query()->count();
    $expectedActive = User::query()->where('is_active', true)->count();
    $expectedInactive = User::query()->where('is_active', false)->count();
    $expectedAdminAccess = User::query()->administrativeAccess()->count();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'dime que cantidad de usuarios hay, cuantos de ellos activos e inactivos y cuantos admin tenemos',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.combined')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedTotal)
        ->assertJsonPath('response.cards.0.data.breakdown.active', $expectedActive)
        ->assertJsonPath('response.cards.0.data.breakdown.inactive', $expectedInactive)
        ->assertJsonPath('response.cards.0.data.breakdown.admin_access', $expectedAdminAccess);

    expect($response->json('response.answer'))
        ->toContain("Hay {$expectedTotal} usuarios en total.")
        ->toContain("{$expectedActive} activos y {$expectedInactive} inactivos.")
        ->toContain("Hay {$expectedAdminAccess} usuarios con acceso administrativo efectivo.");
});

it('supports search by name through the real route path', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca al usuario Daniel Query Unique',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.count', 1)
        ->assertJsonPath('response.cards.0.data.users.0.id', $fixtures['daniel']->id)
        ->assertJsonPath('response.cards.0.data.users.0.email', $fixtures['daniel']->email);
});

it('supports search by email through the real route path', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca al usuario sara.support@example.com',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.count', 1)
        ->assertJsonPath('response.cards.0.data.users.0.id', $fixtures['saraSupport']->id)
        ->assertJsonPath('response.cards.0.data.users.0.email', $fixtures['saraSupport']->email);
});

it('supports admin access phrasing as an effective administrative-access search', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Quienes tienen acceso de administrador',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.matching_count', 3);

    expect(collect($response->json('response.cards.0.data.users'))->pluck('id')->all())
        ->toEqualCanonicalizing([
            $fixtures['aliceAdmin']->id,
            $fixtures['martaAdmin']->id,
            $fixtures['sandraSuperAdmin']->id,
        ]);
});

it('supports colloquial admin collection phrasing', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'quienes son los usuarios admin'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.fallback', false);
});

it('supports minor admin typos while keeping tool-backed search truth', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Lista usuarios con acceso adminsitrador activos',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.matching_count', 3);

    expect(collect($response->json('response.cards.0.data.users'))->pluck('id')->all())
        ->toEqualCanonicalizing([
            $fixtures['aliceAdmin']->id,
            $fixtures['martaAdmin']->id,
            $fixtures['sandraSuperAdmin']->id,
        ]);
});

it('supports explicit super-admin queries as role-backed search', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'lista los usuarios con permisos de super admin',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.matching_count', 1);

    expect(collect($response->json('response.cards.0.data.users'))->pluck('id')->all())
        ->toEqualCanonicalizing([$fixtures['sandraSuperAdmin']->id]);
});

it('supports counting effective admin users deterministically', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'cuantos admin tenemos',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.admin_access')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', 3);
});

it('supports detail by email and includes effective access data', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'el usuario sara.support@example.com que permisos tiene y que rol',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.meta.capability_key', 'users.detail')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.data.user.id', $fixtures['saraSupport']->id)
        ->assertJsonPath('response.cards.0.data.user.email', $fixtures['saraSupport']->email)
        ->assertJsonPath('response.cards.0.data.roles.0.display_name', 'Soporte');

    expect($this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Explica el acceso efectivo de Sara Support',
        ])
        ->json('response.cards.0.data.effective_permissions'))->not->toBeEmpty();
});

it('supports short active and inactive count prompts', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $active = User::query()->where('is_active', true)->count();
    $inactive = User::query()->where('is_active', false)->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'cuantos activos hay'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.cards.0.data.metric.value', $active);

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'cuantos inactivos hay'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.inactive')
        ->assertJsonPath('response.cards.0.data.metric.value', $inactive);
});

it('supports direct role search prompts without explicit verbs', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();
    $adminCount = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'usuarios con rol admin'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.cards.0.data.matching_count', $adminCount);

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'usuarios con rol super admin'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.cards.0.data.matching_count', 1)
        ->assertJsonPath('response.cards.0.data.users.0.id', $fixtures['sandraSuperAdmin']->id);
});

it('supports searching users by effective permission', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'quien puede crear roles'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'search_results');
});

it('supports explaining why a specific user can perform an action', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'por que '.$fixtures['saraSupport']->email.' puede editar usuarios'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.explain.permission')
        ->assertJsonPath('response.meta.fallback', false);
});

it('supports explaining why one user can or cannot act on another user', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'por que '.$fixtures['aliceAdmin']->email.' no puede desactivar al usuario '.$fixtures['daniel']->email])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.explain.action')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'notice');
});

it('supports permission detail by explicit email', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'que permisos tiene '.$fixtures['saraSupport']->email])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.detail')
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.meta.fallback', false);
});

it('supports capabilities summary prompts for a specific user', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'que puede hacer el usuario '.$fixtures['saraSupport']->email])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.explain.capabilities_summary')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'notice');
});

it('supports negative capabilities summary prompts for a specific user', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'que no puede hacer el usuario '.$fixtures['saraSupport']->email]);

    $response->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.explain.capabilities_summary')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'notice');

    expect($response->json('response.answer'))->toContain('No puede:');
});

it('returns read_explain as intent_family for explain capabilities', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'que puede hacer el usuario '.$fixtures['saraSupport']->email])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.intent_family', 'read_explain');

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'por que '.$fixtures['saraSupport']->email.' puede editar usuarios'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.intent_family', 'read_explain');
});

it('supports capabilities summary from subject user context', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'que puede hacer',
            'subject_user_id' => $fixtures['saraSupport']->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.explain.capabilities_summary')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'notice');
});

it('supports listing the active roles catalog', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), ['prompt' => 'cuales roles existen'])
        ->assertSuccessful()
        ->assertJsonPath('response.meta.capability_key', 'users.roles.catalog')
        ->assertJsonPath('response.cards.0.kind', 'notice')
        ->assertJsonPath('response.meta.fallback', false);
});

it('returns clarification only when the user reference is truly ambiguous', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Revisa al usuario Mario',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'ambiguous')
        ->assertJsonPath('response.meta.capability_key', 'users.clarification')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'clarification');

    expect($response->json('response.cards.0.data.options'))->toHaveCount(2);
});

it('supports follow-up counts after a prior result set', function () {
    $operator = promptBatteryOperator();
    seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $conversationId = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos',
        ])
        ->assertSuccessful()
        ->json('conversation_id');

    $expectedInactive = User::query()->where('is_active', false)->count();

    $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Y cuantos son',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.meta.capability_key', 'users.snapshot.result_count')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedInactive)
        ->assertJsonPath('response.answer', "El subconjunto actual tiene {$expectedInactive} usuarios.");
});

it('returns mixed metrics plus a filtered listing in one response', function () {
    $operator = promptBatteryOperator();
    $fixtures = seedPromptBatteryFixtures();
    fakeGeminiPromptBattery();

    $expectedTotal = User::query()->count();
    $expectedActive = User::query()->where('is_active', true)->count();
    $expectedInactive = User::query()->where('is_active', false)->count();
    $expectedAdminRole = User::query()
        ->administrativeAccess()
        ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
        ->count();

    $response = $this->actingAs($operator)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'lista la cantidad de usuarios activos, inactivos y el rol mas comun y listame que usuarios son admin',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.mixed.metrics_search')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.metric.value', $expectedTotal)
        ->assertJsonPath('response.cards.0.data.breakdown.active', $expectedActive)
        ->assertJsonPath('response.cards.0.data.breakdown.inactive', $expectedInactive)
        ->assertJsonPath('response.cards.1.kind', 'search_results')
        ->assertJsonPath('response.cards.1.data.matching_count', $expectedAdminRole);

    expect($response->json('response.answer'))
        ->toContain("{$expectedActive} activos y {$expectedInactive} inactivos.")
        ->toContain('El rol mas comun es Admin con 3 usuarios asignados.')
        ->toContain("Ademas, encontre {$expectedAdminRole} usuarios con acceso administrativo efectivo.")
        ->and(collect($response->json('response.cards.1.data.users'))->pluck('id')->all())
        ->toEqualCanonicalizing(User::query()
            ->administrativeAccess()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->pluck('id')
            ->all());
});
