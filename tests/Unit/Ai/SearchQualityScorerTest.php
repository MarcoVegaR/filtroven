<?php

use App\Ai\Support\SearchQualityScorer;

/**
 * Fase 3: Tests del scorer deterministico de calidad de busqueda.
 */
it('scores a perfect single-match search as high quality', function () {
    $result = SearchQualityScorer::score(
        requestedFilters: ['query' => 'mario', 'status' => 'active'],
        appliedFilters: ['query' => 'mario', 'status' => 'active'],
        resultCount: 1,
    );

    expect($result['quality'])->toBe('high');
    expect($result['coverage'])->toBe(1.0);
    expect($result['has_results'])->toBeTrue();
});

it('marks empty searches as empty quality', function () {
    $result = SearchQualityScorer::score(
        requestedFilters: ['query' => 'usuario_inexistente_xyz'],
        appliedFilters: ['query' => 'usuario_inexistente_xyz'],
        resultCount: 0,
    );

    expect($result['quality'])->toBe('empty');
    expect($result['has_results'])->toBeFalse();
});

it('penalizes coverage when applied filters do not match requested', function () {
    $result = SearchQualityScorer::score(
        requestedFilters: ['query' => 'mario', 'role' => 'admin', 'status' => 'active'],
        appliedFilters: ['query' => 'mario'],
        resultCount: 5,
    );

    expect($result['coverage'])->toBeLessThan(0.5);
    expect($result['quality'])->toBeIn(['low', 'medium']);
});

it('gives medium quality for large result sets with full coverage', function () {
    $result = SearchQualityScorer::score(
        requestedFilters: ['status' => 'active'],
        appliedFilters: ['status' => 'active'],
        resultCount: 75,
    );

    expect($result['quality'])->toBeIn(['medium', 'low']);
    expect($result['has_results'])->toBeTrue();
});

it('treats empty requested filters as full coverage by convention', function () {
    $result = SearchQualityScorer::score(
        requestedFilters: [],
        appliedFilters: [],
        resultCount: 3,
    );

    expect($result['coverage'])->toBe(1.0);
});

it('increases specificity with more applied filters', function () {
    $oneFilter = SearchQualityScorer::score(
        requestedFilters: ['query' => 'mario'],
        appliedFilters: ['query' => 'mario'],
        resultCount: 3,
    );

    $threeFilters = SearchQualityScorer::score(
        requestedFilters: ['query' => 'mario', 'status' => 'active', 'role' => 'admin'],
        appliedFilters: ['query' => 'mario', 'status' => 'active', 'role' => 'admin'],
        resultCount: 3,
    );

    expect($threeFilters['specificity'])->toBeGreaterThanOrEqual($oneFilter['specificity']);
});
