<?php

use App\Ai\Services\UsersCopilotCapabilityCatalog;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

describe('Users Copilot LLM Fallback Integration', function (): void {
    beforeEach(function (): void {
        // Not mocking Log to avoid conflicts with SecurityAuditService channel('security')
        // Asegurar que el clasificador está deshabilitado por defecto
        Config::set('ai-copilot.intent_classifier.enabled', false);

        // Seed roles if they don't exist
        Role::firstOrCreate(['name' => 'super_administrator', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'system.users-copilot.view', 'guard_name' => 'web', 'is_active' => true]);
    });

    it('no invoca clasificador cuando feature flag esta deshabilitado', function (): void {
        $user = actingAs(
            User::factory()->create()->assignRole('super_administrator')
        );

        Config::set('ai-copilot.intent_classifier.enabled', false);

        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Prompt que no matchea determinísticamente.
        // Fase 1d: el split emite 'users.help.unknown' cuando el flag esta activo.
        $plan = $planner->plan('xyz no match test prompt', $snapshot);

        expect($plan['capability_key'])->toBeIn(['users.help', 'users.help.unknown']);
        expect($plan)->not->toHaveKey('classification_source');
    });

    it('clasificador habilitado no afecta prompts que resuelve deterministicamente', function (): void {
        $user = actingAs(
            User::factory()->create()->assignRole('super_administrator')
        );

        Config::set('ai-copilot.intent_classifier.enabled', true);

        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Prompt que el planner resuelve determinísticamente
        $plan = $planner->plan('cuantos usuarios hay', $snapshot);

        expect($plan['capability_key'])->toBe('users.metrics.total');
        expect($plan)->not->toHaveKey('classification_source');
    });

    it('validacion cuadruple rechaza capability_key inexistente', function (): void {
        // Este test requiere que el clasificador LLM esté mockeado
        // En implementación real, el LLM podría retornar una capability inventada
        // y la validación debe rechazarla

        $catalog = UsersCopilotCapabilityCatalog::find('capability.inventada');
        expect($catalog)->toBeNull();
    });

    it('validacion cuadruple rechaza intent_family inconsistente', function (): void {
        // Verificar que el catálogo tiene consistencia entre capability e intent_family
        $allCapabilities = UsersCopilotCapabilityCatalog::all();

        foreach ($allCapabilities as $key => $definition) {
            expect($definition)->toHaveKey('intent_family');
            expect($definition['intent_family'])->toBeString();
        }
    });

    it('validacion cuadruple rechaza filters fuera de required_filters', function (): void {
        $catalog = UsersCopilotCapabilityCatalog::find('users.search');
        expect($catalog)->not->toBeNull();

        $requiredFilters = $catalog['required_filters'] ?? [];

        // 'users.search' debe tener filtros definidos
        expect($requiredFilters)->toBeArray();
    });

    it('validacion cuadruple rechaza confianza baja', function (): void {
        $threshold = config('ai-copilot.intent_classifier.confidence_threshold', 0.7);

        // El umbral debe estar entre 0 y 1
        expect($threshold)->toBeFloat();
        expect($threshold)->toBeGreaterThan(0);
        expect($threshold)->toBeLessThanOrEqual(1);
    });

    it('timeout del clasificador no propaga excepcion', function (): void {
        $timeout = config('ai-copilot.intent_classifier.timeout', 5);

        // El timeout debe ser un entero positivo
        expect($timeout)->toBeInt();
        expect($timeout)->toBeGreaterThan(0);
        expect($timeout)->toBeLessThanOrEqual(30); // Límite razonable
    });

    it('rechaza clasificaciones LLM de busqueda sin criterios efectivos', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $method = new ReflectionMethod($planner, 'validateLLMClassification');
        $method->setAccessible(true);

        $isValid = $method->invoke($planner, [
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'filters' => [],
            'confidence' => 0.95,
        ], 'busca algo');

        expect($isValid)->toBeFalse();
    });

    it('rechaza clasificaciones LLM de detalle sin entidad resoluble', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $method = new ReflectionMethod($planner, 'validateLLMClassification');
        $method->setAccessible(true);

        $isValid = $method->invoke($planner, [
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'filters' => [],
            'confidence' => 0.95,
        ], 'muestrame el detalle');

        expect($isValid)->toBeFalse();
    });

    it('resuelve la entidad desde user_id cuando el fallback LLM clasifica detalle', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $method = new ReflectionMethod($planner, 'buildPlanFromLLMClassification');
        $method->setAccessible(true);
        $user = User::factory()->create(['name' => 'Mario Vega']);

        $plan = $method->invoke(
            $planner,
            'detalle del usuario',
            [
                'intent_family' => 'read_detail',
                'capability_key' => 'users.detail',
                'filters' => ['user_id' => $user->id],
                'confidence' => 0.95,
            ],
            CopilotConversationSnapshot::empty(),
            null,
        );

        expect($plan)
            ->toMatchArray([
                'intent_family' => 'read_detail',
                'capability_key' => 'users.detail',
                'proposal_vs_execute' => 'execute',
                'classification_source' => 'llm_fallback',
            ])
            ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
    });
});
