<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

test('GET without X-Correlation-ID generates a valid UUID response header', function () {
    $response = $this->get(route('home'));

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->not->toBeNull()
        ->and(Str::isUuid($correlationId))->toBeTrue();
});

test('GET with a valid UUID X-Correlation-ID echoes it back', function () {
    $uuid = (string) Str::uuid();

    $response = $this->withHeader('X-Correlation-ID', $uuid)->get(route('home'));

    expect($response->headers->get('X-Correlation-ID'))->toBe($uuid);
});

test('GET with an invalid X-Correlation-ID generates a fresh UUID instead of echoing it', function () {
    $invalid = 'not-a-uuid';

    $response = $this->withHeader('X-Correlation-ID', $invalid)->get(route('home'));

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->not->toBe($invalid)
        ->and(Str::isUuid($correlationId))->toBeTrue();
});

test('authenticated request sets user_id in correlation context', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'));

    expect(Context::get('user_id'))->toBe($user->id);
});

test('guest request sets user_id as null in correlation context', function () {
    $this->get(route('home'));

    expect(Context::get('user_id'))->toBeNull();
});

test('context correlation_id propagates to dispatched jobs', function () {
    // §4.3: When a job is dispatched from a request with correlation_id,
    // the job inherits that ID automatically through Laravel's Context facade.
    // No additional code is required — this test documents that contract.
    $knownId = (string) Str::uuid();
    Context::add('correlation_id', $knownId);

    $capturedId = null;

    // Dispatch a closure job synchronously so it runs inline and can read Context.
    // Laravel's Context facade automatically propagates to synchronous jobs.
    Bus::dispatchSync(function () use (&$capturedId): void {
        $capturedId = Context::get('correlation_id');
    });

    expect($capturedId)->toBe($knownId);
});
