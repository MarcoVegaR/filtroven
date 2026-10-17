<?php

namespace App\Ai\Support;

/**
 * Fase 3: Scorer deterministico para resultados de busqueda.
 *
 * Evalua la calidad semantica de un resultado considerando:
 * - coverage: fraccion de criterios solicitados que realmente se aplicaron.
 * - specificity: densidad de filtros concretos (query + flags booleanos).
 * - result_signal: si hubo matches, con preferencia por conjuntos pequenos.
 *
 * Se usa para decidir cuando emitir `partial_response` (respuesta honesta
 * con menos del 100% de lo pedido) en mixed intent y en busquedas parciales.
 */
class SearchQualityScorer
{
    /**
     * @param  array<string, mixed>  $requestedFilters  filtros que el usuario pidio.
     * @param  array<string, mixed>  $appliedFilters  filtros efectivamente aplicados.
     * @param  int  $resultCount  cantidad de resultados devueltos.
     * @return array{
     *     score: float,
     *     coverage: float,
     *     specificity: float,
     *     has_results: bool,
     *     quality: 'high'|'medium'|'low'|'empty'
     * }
     */
    public static function score(array $requestedFilters, array $appliedFilters, int $resultCount): array
    {
        $requested = self::activeFilters($requestedFilters);
        $applied = self::activeFilters($appliedFilters);

        $coverage = $requested === []
            ? 1.0
            : count(array_intersect_key($applied, $requested)) / max(1, count($requested));

        $specificity = min(1.0, count($applied) / 3);

        $hasResults = $resultCount > 0;
        $resultSignal = match (true) {
            ! $hasResults => 0.0,
            $resultCount === 1 => 1.0,
            $resultCount <= 10 => 0.85,
            $resultCount <= 50 => 0.65,
            default => 0.4,
        };

        $score = ($coverage * 0.5) + ($specificity * 0.2) + ($resultSignal * 0.3);

        $quality = match (true) {
            ! $hasResults => 'empty',
            $score >= 0.8 => 'high',
            $score >= 0.55 => 'medium',
            default => 'low',
        };

        return [
            'score' => round($score, 3),
            'coverage' => round($coverage, 3),
            'specificity' => round($specificity, 3),
            'has_results' => $hasResults,
            'quality' => $quality,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected static function activeFilters(array $filters): array
    {
        return array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== false,
        );
    }
}
