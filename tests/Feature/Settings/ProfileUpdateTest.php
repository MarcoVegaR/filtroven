<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('ui.locale', 'es')
            ->where('ui.settingsSection.title', 'Configuración')
            ->where('ui.settingsNavigation.0.title', 'Perfil')
            ->where('ui.settingsNavigation.1.title', 'Seguridad')
            ->where('ui.settingsNavigation.2.title', 'Acceso')
            ->where('ui.settingsNavigation.3.title', 'Apariencia')
            ->where('canDeleteAccount', false),
        );
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('profile deletion route is not exposed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile')
        ->assertMethodNotAllowed();
});

test('profile validation feedback is presented in spanish', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => '',
            'email' => 'correo-invalido',
        ])
        ->assertSessionHasErrors([
            'name' => __('validation.required', ['attribute' => __('validation.attributes.name')]),
            'email' => __('validation.email', ['attribute' => __('validation.attributes.email')]),
        ]);
});
