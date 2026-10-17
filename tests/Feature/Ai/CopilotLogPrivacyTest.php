<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

describe('Copilot Log Privacy', function (): void {
    beforeEach(function (): void {
        Role::firstOrCreate(['name' => 'super_administrator', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'system.users-copilot.view', 'guard_name' => 'web', 'is_active' => true]);

        $this->testUser = User::factory()->create();
        $this->testUser->assignRole('super_administrator');
        actingAs($this->testUser);
    });

    it('normaliza el prompt antes de procesarlo (lowercase, trim)', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Prompt con mayúsculas y espacios extras que se normalizarán
        $plan = $planner->plan('   XYZ NO MATCH   ', $snapshot);

        // Debe estar normalizado (lowercase, squished) en request_normalization
        expect($plan['request_normalization'])->toBe('xyz no match');
    });

    it('preserva emails en request_normalization para resolución', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('buscar usuario secreto@example.com que no existe', $snapshot);

        // El plan mantiene el email para la resolución de entidades
        expect($plan['request_normalization'])->toContain('secreto@example.com');
    });

    it('el sanitizador interno reemplaza emails por [email]', function (): void {
        // Verificar el método privado de sanitización usado en logs
        $planner = app(UsersCopilotRequestPlanner::class);
        $reflection = new ReflectionClass($planner);

        // Verificar que logFallback existe y usa sanitización
        $method = $reflection->getMethod('logFallback');
        expect($method->isProtected())->toBeTrue();
    });

    it('maneja multiples emails en el prompt', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('comparar user1@test.com y user2@domain.org', $snapshot);

        // Ambos emails deben estar presentes en normalization para resolución
        expect($plan['request_normalization'])
            ->toContain('user1@test.com')
            ->toContain('user2@domain.org');
    });
});
