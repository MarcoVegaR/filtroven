<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

describe('Copilot Email Integration Debug', function (): void {
    beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AccessModulePermissionsSeeder::class);
        $this->seed(AiCopilotPermissionsSeeder::class);

        config(['ai-copilot.enabled' => true]);

        $role = Role::factory()->active()->create();
        $role->syncPermissions(['system.users.view', 'system.users-copilot.view']);

        // Crear usuario de prueba con email test@mailinator.com
        $this->testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@mailinator.com',
            'is_active' => true,
        ]);

        // Crear actor con permisos de copilot
        $this->actor = User::factory()->create();
        $this->actor->assignRole($role);
    });

    it('debug completo del flujo HTTP para email existente', function (): void {
        echo "\n=== DEBUG INTEGRACIÓN ===\n";
        echo 'Usuario test existe: ID '.$this->testUser->id.', Email: '.$this->testUser->email."\n";

        $response = $this->actingAs($this->actor)
            ->postJson(route('system.users.copilot.messages'), [
                'prompt' => 'quien es el usuario test@mailinator.com',
            ]);

        echo 'HTTP Status: '.$response->status()."\n";

        if ($response->status() !== 200) {
            echo 'Response body: '.$response->content()."\n";

            return;
        }

        $data = $response->json();

        echo 'Response intent: '.($data['response']['intent'] ?? 'N/A')."\n";
        echo 'Response capability: '.($data['response']['meta']['capability_key'] ?? 'N/A')."\n";
        echo 'Response fallback: '.(($data['response']['meta']['fallback'] ?? false) ? 'YES' : 'NO')."\n";
        echo 'Response cards count: '.count($data['response']['cards'] ?? [])."\n";

        if (isset($data['response']['cards'][0])) {
            $card = $data['response']['cards'][0];
            echo 'First card kind: '.($card['kind'] ?? 'N/A')."\n";

            if (isset($card['data']['user'])) {
                echo 'User ID in card: '.$card['data']['user']['id']."\n";
                echo 'User email in card: '.$card['data']['user']['email']."\n";
            }
        }

        // Verificaciones
        $response->assertSuccessful();
        expect($data['response']['intent'])->toBe('user_context');
        expect($data['response']['meta']['fallback'])->toBeFalse();
        expect($data['response']['cards'])->toHaveCount(1);
        expect($data['response']['cards'][0]['data']['user']['id'])->toBe($this->testUser->id);

        echo "\n=== ÉXITO: El flujo funciona correctamente ===\n";
    });

    it('debug para email que no existe', function (): void {
        echo "\n=== DEBUG EMAIL NO EXISTE ===\n";

        $response = $this->actingAs($this->actor)
            ->postJson(route('system.users.copilot.messages'), [
                'prompt' => 'quien es el usuario nonexistent@example.com',
            ]);

        $data = $response->json();

        echo 'Response intent: '.($data['response']['intent'] ?? 'N/A')."\n";
        echo 'Response capability: '.($data['response']['meta']['capability_key'] ?? 'N/A')."\n";

        if (isset($data['response']['cards'][0]['data'])) {
            echo 'Clarification reason: '.($data['response']['cards'][0]['data']['reason'] ?? 'N/A')."\n";
            echo 'Clarification question: '.($data['response']['cards'][0]['data']['question'] ?? 'N/A')."\n";
        }

        $response->assertSuccessful();
        expect($data['response']['intent'])->toBe('ambiguous');
        expect($data['response']['meta']['capability_key'])->toBe('users.clarification');
        expect($data['response']['cards'][0]['kind'])->toBe('clarification');

        echo "\n=== ÉXITO: Manejo correcto de email no existente ===\n";
    });
});
