<?php

use App\Ai\Support\UserFuzzyMatcher;
use App\Models\User;

/**
 * Fase 4a: Fuzzy matching tolerante.
 *
 * En SQLite el matcher cae al fallback PHP (similar_text). En PostgreSQL real
 * usa pg_trgm con el indice GIN. Ambos deben producir candidatos razonables.
 */
it('finds similar users by name using fuzzy matching', function () {
    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario@example.com']);
    User::factory()->create(['name' => 'Carlos Mendez', 'email' => 'carlos@example.com']);
    User::factory()->create(['name' => 'Sara Lopez', 'email' => 'sara@example.com']);

    $matches = (new UserFuzzyMatcher)->matchByName('mario', threshold: 0.3);

    expect($matches)->not->toBeEmpty();
    expect($matches->first()['name'])->toBe('Mario Vega');
});

it('tolerates minor typos in names', function () {
    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario@example.com']);

    $matches = (new UserFuzzyMatcher)->matchByName('Maryo Vega', threshold: 0.3);

    expect($matches)->not->toBeEmpty();
    expect($matches->first()['name'])->toBe('Mario Vega');
});

it('returns empty collection when threshold is too high', function () {
    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario@example.com']);

    $matches = (new UserFuzzyMatcher)->matchByName('xyz123nonexistent', threshold: 0.9);

    expect($matches)->toBeEmpty();
});

it('finds users by partial email similarity', function () {
    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario.vega@example.com']);
    User::factory()->create(['name' => 'Other', 'email' => 'other@example.com']);

    $matches = (new UserFuzzyMatcher)->matchByEmail('mario.vega', threshold: 0.3);

    expect($matches)->not->toBeEmpty();
    expect($matches->first()['email'])->toContain('mario.vega');
});
