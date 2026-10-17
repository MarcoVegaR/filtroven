<?php

use App\Exceptions\BoilerplateException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Concrete subclass for testing — BoilerplateException is abstract.
 */
class TestBoilerplateException extends BoilerplateException
{
    protected string $shortCode = 'TEST_ERROR';

    protected int $statusCode = 422;
}

/**
 * Concrete subclass with a custom status code.
 */
class TestConflictException extends BoilerplateException
{
    protected string $shortCode = 'CONFLICT';

    protected int $statusCode = 409;
}

test('browser request renders error-page Inertia component with the exception status', function () {
    Route::get('/_test/bp-inertia', fn () => throw new TestBoilerplateException('TEST_ERROR message'))->middleware('web');

    // Browser request (no X-Inertia header) — renders the full Inertia page with status 422
    $this->get('/_test/bp-inertia')
        ->assertStatus(422)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 422)
        );
});

test('JSON request returns structured error JSON with shortCode and message', function () {
    Route::get('/_test/bp-json', fn () => throw new TestBoilerplateException('TEST_ERROR message'))->middleware('web');

    $this->getJson('/_test/bp-json')
        ->assertStatus(422)
        ->assertJsonPath('error', 'TEST_ERROR')
        ->assertJsonPath('message', 'TEST_ERROR message');
});

test('custom status 409 returns correct HTTP status code', function () {
    Route::get('/_test/bp-conflict', fn () => throw new TestConflictException('Conflict'))->middleware('web');

    $this->getJson('/_test/bp-conflict')
        ->assertStatus(409)
        ->assertJsonPath('error', 'CONFLICT');
});

test('BoilerplateException with status 403 responds with HTTP 403 via early return', function () {
    Route::get('/_test/bp-forbidden', fn () => throw new TestBoilerplateException('Forbidden', 403))->middleware('web');

    $this->getJson('/_test/bp-forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error', 'TEST_ERROR');
});
