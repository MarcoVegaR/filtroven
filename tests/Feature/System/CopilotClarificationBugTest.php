<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

describe('Copilot Clarification Bug Fix', function (): void {
    beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AccessModulePermissionsSeeder::class);
        $this->seed(AiCopilotPermissionsSeeder::class);

        config(['ai-copilot.enabled' => true]);

        $role = Role::factory()->active()->create();
        $role->syncPermissions(['system.users.view', 'system.users-copilot.view']);

        $this->actor = User::factory()->create();
        $this->actor->assignRole($role);
    });

    it('maneja correctamente email que no existe con clarification', function (): void {
        // Test con email que no existe
        $response = $this->actingAs($this->actor)
            ->postJson(route('system.users.copilot.messages'), [
                'prompt' => 'quien es el usuario nonexistent@example.com',
            ]);

        $response->assertSuccessful();
        $data = $response->json();

        // Debe retornar ambiguous con una tarjeta de clarification estructurada
        expect($data['response']['intent'])->toBe('ambiguous');
        expect($data['response']['meta']['capability_key'])->toBe('users.clarification');
        expect($data['response']['cards'][0]['kind'])->toBe('clarification');
        expect($data['response']['cards'][0]['data']['reason'])->toBe('missing_entity');
        expect($data['response']['cards'][0]['data']['question'])->toContain('nonexistent@example.com');
    });

    it('ejecuta correctamente email que existe', function (): void {
        // Crear usuario de prueba
        $testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@mailinator.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->actor)
            ->postJson(route('system.users.copilot.messages'), [
                'prompt' => 'quien es el usuario test@mailinator.com',
            ]);

        $response->assertSuccessful();
        $data = $response->json();

        // Debe ejecutar exitosamente
        expect($data['response']['intent'])->toBe('user_context');
        expect($data['response']['meta']['fallback'])->toBeFalse();
        expect($data['response']['cards'])->toHaveCount(1);
        expect($data['response']['cards'][0]['data']['user']['id'])->toBe($testUser->id);
    });
});
