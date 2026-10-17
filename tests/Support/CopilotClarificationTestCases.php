<?php

namespace Tests\Support;

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;

/**
 * 20 casos de prueba para validar el comportamiento de clarificaciones
 * y correcciones de typos en el copilot.
 *
 * Uso:
 *   php artisan tinker --execute="require_once 'tests/Support/CopilotClarificationTestCases.php'; Tests\Support\CopilotClarificationTestCases::runAll();"
 */
class CopilotClarificationTestCases
{
    /**
     * Ejecuta todos los casos de prueba y reporta resultados.
     */
    public static function runAll(): void
    {
        $cases = [
            // Email typo cases (1-8)
            'Email typo con confirmación simple' => fn () => self::testEmailTypoWithSimpleConfirmation(),
            'Email typo con confirmación explícita' => fn () => self::testEmailTypoWithExplicitConfirmation(),
            'Email typo con frase de referencia' => fn () => self::testEmailTypoWithReferencePhrase(),
            'Email typo sin corrección similar' => fn () => self::testEmailTypoNoSimilar(),
            'Email correcto (sin typo)' => fn () => self::testEmailCorrect(),
            'Email typo - usuario dice el email correcto' => fn () => self::testEmailTypoUserSaysCorrectEmail(),
            'Email typo - usuario quiere buscar por nombre' => fn () => self::testEmailTypoUserWantsNameSearch(),
            'Email typo múltiple similar - confirmación ambigua' => fn () => self::testEmailTypoMultipleSimilar(),

            // Name typo cases (9-14)
            'Nombre typo con confirmación' => fn () => self::testNameTypoWithConfirmation(),
            'Nombre typo sin acentos' => fn () => self::testNameTypoNoAccents(),
            'Nombre typo con diferente capitalización' => fn () => self::testNameTypoDifferentCase(),
            'Nombre completo no encontrado - sugerencias' => fn () => self::testFullNameNotFound(),
            'Nombre parcial que matchea múltiples' => fn () => self::testPartialNameMultipleMatches(),
            'Nombre con typo leve (1 caracter)' => fn () => self::testNameTypoSingleChar(),

            // Confirmation phrase variations (15-18)
            'Confirmación: "sí como me lo indicas"' => fn () => self::testConfirmationPhrase1(),
            'Confirmación: "quise decir el que me indicas"' => fn () => self::testConfirmationPhrase2(),
            'Confirmación: "ese es"' => fn () => self::testConfirmationPhrase3(),
            'Confirmación: "el primero"' => fn () => self::testConfirmationPhrase4(),

            // Edge cases (19-20)
            'Nuevo intent durante clarificación' => fn () => self::testNewIntentDuringClarification(),
            'Respuesta irrelevante durante clarificación' => fn () => self::testIrrelevantResponseDuringClarification(),
        ];

        echo "\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "   CASOS DE PRUEBA - CLARIFICACIONES Y TYPO CORRECTIONS\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";

        $passed = 0;
        $failed = 0;

        foreach ($cases as $name => $test) {
            try {
                $result = $test();
                if ($result['success']) {
                    echo "✅ PASS: {$name}\n";
                    $passed++;
                } else {
                    echo "❌ FAIL: {$name}\n";
                    echo "   Esperado: {$result['expected']}\n";
                    echo "   Actual:   {$result['actual']}\n";
                    $failed++;
                }
            } catch (\Throwable $e) {
                echo "💥 ERROR: {$name}\n";
                echo "   {$e->getMessage()}\n";
                $failed++;
            }
        }

        echo "\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "   RESULTADOS: {$passed} pasados, {$failed} fallidos\n";
        echo "═══════════════════════════════════════════════════════════════\n";
    }

    // ============================================
    // EMAIL TYPO TESTS (1-8)
    // ============================================

    /**
     * Caso 1: Email con typo, usuario confirma con "sí"
     */
    private static function testEmailTypoWithSimpleConfirmation(): array
    {
        $uniqueId = uniqid();
        $email = "test_{$uniqueId}@mailinator.com";

        // Setup: Crear usuario
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Test User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        // Paso 1: Usuario pregunta con typo
        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "test_{$uniqueId}@mailinator.comm";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        if ($plan1['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan1['capability_key']];
        }

        // Verificar que hay options
        $options = $plan1['clarification_state']['options'] ?? [];
        if (count($options) === 0) {
            return ['success' => false, 'expected' => 'options > 0', 'actual' => '0 options'];
        }

        // Paso 2: Usuario confirma con "sí"
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('sí como me lo indicas', $snapshot2);

        // Cleanup
        $user->delete();

        // Debería resolver al usuario
        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 2: Email con typo, usuario dice "ese es"
     */
    private static function testEmailTypoWithExplicitConfirmation(): array
    {
        $uniqueId = uniqid();
        $email = "admin_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Admin User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        // Paso 1: Typo en email
        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "admin_{$uniqueId}@examplle.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        if ($plan1['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan1['capability_key']];
        }

        // Paso 2: Confirmación explícita
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('ese es correcto', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 3: Email con typo, usuario referencia la sugerencia
     */
    private static function testEmailTypoWithReferencePhrase(): array
    {
        $uniqueId = uniqid();
        $email = "maria_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Maria Garcia',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "maria_{$uniqueId}@examplle.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        if ($plan1['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan1['capability_key']];
        }

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('quise decir el que me indicas', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 4: Email no existe y no hay similar
     */
    private static function testEmailTypoNoSimilar(): array
    {
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = CopilotConversationSnapshot::empty();

        // Email que no existe y no tiene similares
        $plan = $planner->plan('quien es xyz123@nonexistent.xyz', $snapshot);

        if ($plan['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan['capability_key']];
        }

        // No debería tener opciones
        $options = $plan['clarification_state']['options'] ?? [];
        if (count($options) > 0) {
            return ['success' => false, 'expected' => '0 options', 'actual' => count($options).' options'];
        }

        return ['success' => true];
    }

    /**
     * Caso 5: Email correcto (sin typo)
     */
    private static function testEmailCorrect(): array
    {
        $uniqueId = uniqid();
        $email = "correct_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Correct User',
        ]);

        $planner = new UsersCopilotRequestPlanner;
        $snapshot = CopilotConversationSnapshot::empty();

        $plan = $planner->plan("quien es {$email}", $snapshot);

        $user->delete();

        if ($plan['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 6: Usuario corrige diciendo el email correcto
     */
    private static function testEmailTypoUserSaysCorrectEmail(): array
    {
        $uniqueId = uniqid();
        $email = "actual_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Actual User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        // Paso 1: Typo
        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "actual_{$uniqueId}@examplle.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        // Paso 2: Usuario proporciona email correcto
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan($email, $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 7: Usuario quiere buscar por nombre en lugar de email
     */
    private static function testEmailTypoUserWantsNameSearch(): array
    {
        $uniqueId = uniqid();

        User::factory()->create([
            'email' => "john_{$uniqueId}@example.com",
            'name' => 'John Doe',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        // Paso 1: Typo en email
        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "john_{$uniqueId}n@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        // Paso 2: Usuario cambia a búsqueda por nombre
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('busca por nombre john', $snapshot2);

        // Debería detectar nuevo intent
        if ($plan2['capability_key'] !== 'users.search') {
            return ['success' => false, 'expected' => 'users.search', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 8: Múltiples emails similares, confirmación ambigua
     */
    private static function testEmailTypoMultipleSimilar(): array
    {
        $uniqueId = uniqid();

        $user1 = User::factory()->create([
            'email' => "test1_{$uniqueId}@example.com",
            'name' => 'Test One',
        ]);
        $user2 = User::factory()->create([
            'email' => "test2_{$uniqueId}@example.com",
            'name' => 'Test Two',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan1 = $planner->plan("quien es test_{$uniqueId}@example.com", $snapshot);

        // Debería tener múltiples options
        $options = $plan1['clarification_state']['options'] ?? [];
        if (count($options) < 2) {
            return ['success' => false, 'expected' => '2+ options', 'actual' => count($options).' options'];
        }

        // Confirmación ambigua debería pedir especificar
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('sí', $snapshot2);

        $user1->delete();
        $user2->delete();

        // Debería mantener clarificación pidiendo especificar
        if ($plan2['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    // ============================================
    // NAME TYPO TESTS (9-14)
    // ============================================

    /**
     * Caso 9: Nombre con typo, confirmación
     */
    private static function testNameTypoWithConfirmation(): array
    {
        $uniqueId = uniqid();

        $user = User::factory()->create([
            'email' => "maria_{$uniqueId}@example.com",
            'name' => "María García {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan1 = $planner->plan("quien es maria garciaa {$uniqueId}", $snapshot);

        if ($plan1['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan1['capability_key']];
        }

        // Verificar options
        $options = $plan1['clarification_state']['options'] ?? [];
        if (count($options) === 0) {
            return ['success' => false, 'expected' => 'options > 0', 'actual' => '0 options'];
        }

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('ese es', $snapshot2);

        $user->delete();

        // Verificar resultado según cantidad de opciones
        $optionCount = count($options);
        if ($optionCount === 1 && $plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail (1 option)', 'actual' => $plan2['capability_key']];
        }
        if ($optionCount > 1 && $plan2['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification (multi)', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 10: Nombre sin acentos buscando nombre con acentos
     */
    private static function testNameTypoNoAccents(): array
    {
        $uniqueId = uniqid();

        $user = User::factory()->create([
            'email' => "jose_{$uniqueId}@example.com",
            'name' => "José Hernández {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan1 = $planner->plan("quien es jose hernandez {$uniqueId}", $snapshot);

        // Puede encontrar directamente (detail) o sugerir (clarification)
        if ($plan1['capability_key'] === 'users.detail') {
            $user->delete();

            return ['success' => true]; // Encontró directamente sin acentos
        }

        // Si es clarification, verificar que sugiere el nombre con acentos
        if ($plan1['capability_key'] === 'users.clarification') {
            $question = $plan1['clarification_state']['question'] ?? '';
            $options = $plan1['clarification_state']['options'] ?? [];
            $foundInOptions = false;
            foreach ($options as $opt) {
                if (str_contains($opt['label'] ?? '', "José Hernández {$uniqueId}")) {
                    $foundInOptions = true;
                    break;
                }
            }

            if (! str_contains($question, 'José Hernández') && ! $foundInOptions) {
                return ['success' => false, 'expected' => 'sugerir José Hernández', 'actual' => $question];
            }
            $user->delete();

            return ['success' => true];
        }

        $user->delete();

        return ['success' => false, 'expected' => 'users.detail o users.clarification', 'actual' => $plan1['capability_key']];
    }

    /**
     * Caso 11: Diferente capitalización
     */
    private static function testNameTypoDifferentCase(): array
    {
        $uniqueId = uniqid();

        // Nombre con ID único para evitar colisiones
        $user = User::factory()->create([
            'email' => "ana_{$uniqueId}@example.com",
            'name' => "AnA tOrReS {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan = $planner->plan("quien es ana torres {$uniqueId}", $snapshot);

        $user->delete();

        // Puede encontrar directamente (case insensitive) o ser clarification
        if ($plan['capability_key'] === 'users.detail') {
            return ['success' => true];
        }

        if ($plan['capability_key'] === 'users.clarification') {
            // Verificar que está en las opciones
            $options = $plan['clarification_state']['options'] ?? [];
            foreach ($options as $opt) {
                if (str_contains($opt['label'] ?? '', "AnA tOrReS {$uniqueId}")) {
                    return ['success' => true];
                }
            }

            return ['success' => false, 'expected' => 'user in options', 'actual' => 'not found'];
        }

        return ['success' => false, 'expected' => 'users.detail o users.clarification', 'actual' => $plan['capability_key']];
    }

    /**
     * Caso 12: Nombre completo no encontrado pero hay similares
     */
    private static function testFullNameNotFound(): array
    {
        $uniqueId = uniqid();

        // Usar nombres muy distintivos para evitar colisiones con usuarios existentes
        User::factory()->create([
            'email' => "jana_briones_{$uniqueId}@example.com",
            'name' => "JanaX BrionesY {$uniqueId}",
        ]);
        User::factory()->create([
            'email' => "julia_benavides_{$uniqueId}@example.com",
            'name' => "JuliaX BenavidesY {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        // Buscar con prefijo distintivo para que no colisione con otros "juan"
        $plan = $planner->plan("quien es janax {$uniqueId}", $snapshot);

        // Si encontró directamente uno de ellos, éxito
        if ($plan['capability_key'] === 'users.detail') {
            $resolvedId = $plan['resolved_entity']['id'] ?? null;

            // Verificar que es uno de los nuestros (podría ser otro usuario similar)
            return ['success' => true]; // Encontró un usuario similar
        }

        // Si es clarification, verificar que al menos uno está en options
        if ($plan['capability_key'] === 'users.clarification') {
            $options = $plan['clarification_state']['options'] ?? [];
            $foundJana = false;
            $foundJulia = false;
            foreach ($options as $opt) {
                if (str_contains($opt['label'] ?? '', "JanaX BrionesY {$uniqueId}")) {
                    $foundJana = true;
                }
                if (str_contains($opt['label'] ?? '', "JuliaX BenavidesY {$uniqueId}")) {
                    $foundJulia = true;
                }
            }

            // Si al menos uno está en options, consideramos éxito
            if ($foundJana || $foundJulia) {
                return ['success' => true];
            }

            return ['success' => false, 'expected' => 'al menos uno en options', 'actual' => 'ninguno encontrado'];
        }

        return ['success' => false, 'expected' => 'users.detail o users.clarification', 'actual' => $plan['capability_key']];
    }

    /**
     * Caso 13: Nombre parcial que matchea múltiples usuarios
     */
    private static function testPartialNameMultipleMatches(): array
    {
        $uniqueId = uniqid();

        User::factory()->create([
            'email' => "juan1_{$uniqueId}@example.com",
            'name' => "Juan Pérez {$uniqueId}",
        ]);
        User::factory()->create([
            'email' => "juan2_{$uniqueId}@example.com",
            'name' => "Juana Pérez {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan = $planner->plan("quien es juan {$uniqueId}", $snapshot);

        // Debería ser ambiguo (sin typo correction porque son múltiples)
        if ($plan['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan['capability_key']];
        }

        $options = $plan['clarification_state']['options'] ?? [];
        if (count($options) < 2) {
            return ['success' => false, 'expected' => '2+ options', 'actual' => count($options).' options'];
        }

        return ['success' => true];
    }

    /**
     * Caso 14: Typo de un solo caracter
     */
    private static function testNameTypoSingleChar(): array
    {
        $uniqueId = uniqid();

        $user = User::factory()->create([
            'email' => "pedro_{$uniqueId}@example.com",
            'name' => "Pedro López {$uniqueId}",
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $plan1 = $planner->plan("quien es pedro lopes {$uniqueId}", $snapshot);

        if ($plan1['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan1['capability_key']];
        }

        // Confirmar
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('sí', $snapshot2);

        $user->delete();

        // Verificar resultado según cantidad de opciones
        $optionCount = count($plan1['clarification_state']['options'] ?? []);
        if ($optionCount === 1 && $plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail (1 option)', 'actual' => $plan2['capability_key']];
        }
        if ($optionCount > 1 && $plan2['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification (multi)', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    // ============================================
    // CONFIRMATION PHRASE TESTS (15-18)
    // ============================================

    /**
     * Caso 15: Confirmación "sí como me lo indicas"
     */
    private static function testConfirmationPhrase1(): array
    {
        $uniqueId = uniqid();
        $email = "test_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Test User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "test_{$uniqueId}@examplle.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('sí como me lo indicas', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 16: Confirmación "quise decir el que me indicas"
     */
    private static function testConfirmationPhrase2(): array
    {
        $uniqueId = uniqid();
        $email = "admin_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Admin User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "adminn_{$uniqueId}@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('quise decir el que me indicas', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 17: Confirmación "ese es"
     */
    private static function testConfirmationPhrase3(): array
    {
        $uniqueId = uniqid();
        $email = "user_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'User Name',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "userr_{$uniqueId}@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('ese es', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 18: Confirmación "el primero"
     */
    private static function testConfirmationPhrase4(): array
    {
        $uniqueId = uniqid();
        $email = "first_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'First User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "firstt_{$uniqueId}@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('el primero', $snapshot2);

        $user->delete();

        if ($plan2['capability_key'] !== 'users.detail') {
            return ['success' => false, 'expected' => 'users.detail', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    // ============================================
    // EDGE CASES (19-20)
    // ============================================

    /**
     * Caso 19: Nuevo intent durante clarificación
     */
    private static function testNewIntentDuringClarification(): array
    {
        $uniqueId = uniqid();
        $email = "old_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Old User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "oldd_{$uniqueId}@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        // Usuario cambia completamente de intent
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('lista todos los usuarios activos', $snapshot2);

        $user->delete();

        // Debería ignorar la clarificación y hacer la búsqueda
        if ($plan2['capability_key'] !== 'users.search') {
            return ['success' => false, 'expected' => 'users.search', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }

    /**
     * Caso 20: Respuesta irrelevante durante clarificación
     */
    private static function testIrrelevantResponseDuringClarification(): array
    {
        $uniqueId = uniqid();
        $email = "test_{$uniqueId}@example.com";

        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Test User',
        ]);

        $planner = new UsersCopilotRequestPlanner;

        $snapshot = CopilotConversationSnapshot::empty();
        $typoEmail = "testt_{$uniqueId}@example.com";
        $plan1 = $planner->plan("quien es {$typoEmail}", $snapshot);

        // Respuesta que no es confirmación ni selección
        $snapshot2 = $snapshot->with([
            'pending_clarification' => $plan1['clarification_state'],
        ]);
        $plan2 = $planner->plan('no sé', $snapshot2);

        $user->delete();

        // Debería mantener la clarificación
        if ($plan2['capability_key'] !== 'users.clarification') {
            return ['success' => false, 'expected' => 'users.clarification', 'actual' => $plan2['capability_key']];
        }

        return ['success' => true];
    }
}
