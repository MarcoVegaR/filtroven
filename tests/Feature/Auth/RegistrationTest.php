<?php

use Illuminate\Support\Facades\Route;

test('registration routes are not registered', function () {
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('register.store'))->toBeFalse();
});

test('registration screen returns not found', function () {
    $this->get('/register')->assertNotFound();
});

test('registration submissions return not found', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
