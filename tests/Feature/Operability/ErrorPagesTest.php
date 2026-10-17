<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Error page handler only fires in non-local, non-testing environments
    config(['app.env' => 'production']);
    $this->app['env'] = 'production';
});

test('403 response renders Inertia error-page with status 403', function () {
    Route::get('/_test/403', fn () => abort(403))->middleware('web');

    $this->get('/_test/403')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 403)
        );
});

test('404 response renders Inertia error-page with status 404', function () {
    $this->get('/route-that-does-not-exist-'.uniqid())
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 404)
        );
});

test('500 response renders Inertia error-page with status 500', function () {
    Route::get('/_test/500', fn () => abort(500))->middleware('web');

    $this->get('/_test/500')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 500)
        );
});

test('503 response renders Inertia error-page with status 503', function () {
    Route::get('/_test/503', fn () => abort(503))->middleware('web');

    $this->get('/_test/503')
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 503)
        );
});

test('419 CSRF expiry redirects back with flash message', function () {
    // Session expired (CSRF mismatch) triggers 419 — expects a redirect back with flash
    Route::post('/_test/csrf', fn () => 'ok')->middleware(['web']);

    $response = $this->withSession(['_token' => 'valid-token'])
        ->post('/_test/csrf', [], ['X-CSRF-TOKEN' => 'invalid-token']);

    $response->assertRedirect()
        ->assertSessionHas('message', 'La página expiró, por favor intenta de nuevo.');
});
