<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('dashboard uses filtroven branding and shared layout metadata', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('ui.locale', 'es')
            ->where('name', 'Filtroven')
            ->where('ui.branding.application', 'Filtroven')
            ->where('ui.branding.company', 'Filtroven')
            ->where('ui.navigation.label', 'Navegación')
            ->where('ui.navigation.items.0.title', 'Panel')
            ->where('ui.navigation.items', fn ($items) => collect($items)->doesntContain(
                fn (array $item) => $item['title'] === 'Ayuda'
            ))
            ->where('ui.navigation.starterPromoLinksRemoved', true)
            ->missing('dashboardContent'),
        );
});
