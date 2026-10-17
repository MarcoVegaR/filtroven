<?php

namespace App\Ai\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4a: Matching tolerante de usuarios escalable con pg_trgm.
 *
 * Estrategia:
 * - PostgreSQL: usa `similarity(col, :q)` con threshold configurable y
 *   aprovecha indices GIN (`users_name_trgm_idx`, `users_email_trgm_idx`).
 * - Otros drivers (SQLite en tests): fallback en PHP con similar_text y
 *   Levenshtein limitado.
 *
 * Hereda la convencion de devolver candidatos ordenados por score descendiente
 * con un limite pequeno para evitar sobrecarga al planner.
 */
class UserFuzzyMatcher
{
    public const DEFAULT_THRESHOLD = 0.3;

    public const DEFAULT_LIMIT = 10;

    /**
     * Devuelve candidatos por nombre ordenados por similitud descendente.
     *
     * @return Collection<int, array{user_id: int, name: string, email: string, score: float}>
     */
    public function matchByName(string $query, float $threshold = self::DEFAULT_THRESHOLD, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->matchByColumn('name', $query, $threshold, $limit);
    }

    /**
     * Devuelve candidatos por email ordenados por similitud descendente.
     *
     * @return Collection<int, array{user_id: int, name: string, email: string, score: float}>
     */
    public function matchByEmail(string $query, float $threshold = self::DEFAULT_THRESHOLD, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->matchByColumn('email', $query, $threshold, $limit);
    }

    /**
     * @return Collection<int, array{user_id: int, name: string, email: string, score: float}>
     */
    protected function matchByColumn(string $column, string $query, float $threshold, int $limit): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return $this->matchViaTrgm($column, $query, $threshold, $limit);
        }

        return $this->matchViaFallback($column, $query, $threshold, $limit);
    }

    /**
     * @return Collection<int, array{user_id: int, name: string, email: string, score: float}>
     */
    protected function matchViaTrgm(string $column, string $query, float $threshold, int $limit): Collection
    {
        try {
            /** @var array<int, object{id: int, name: string, email: string, score: float}> $rows */
            $rows = DB::table('users')
                ->select([
                    'id',
                    'name',
                    'email',
                    DB::raw("similarity({$column}, ?) AS score"),
                ])
                ->whereNotNull($column)
                ->whereRaw("similarity({$column}, ?) >= ?", [$query, $threshold])
                ->orderByDesc('score')
                ->limit($limit)
                ->addBinding($query, 'select')
                ->get()
                ->all();

            return collect($rows)->map(fn (object $row): array => [
                'user_id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'score' => (float) $row->score,
            ])->values();
        } catch (\Throwable $e) {
            Log::notice('copilot.fuzzy_matcher.trgm_fallback', [
                'error' => $e->getMessage(),
                'column' => $column,
            ]);

            return $this->matchViaFallback($column, $query, $threshold, $limit);
        }
    }

    /**
     * Fallback PHP-only para SQLite y otros drivers sin pg_trgm.
     *
     * @return Collection<int, array{user_id: int, name: string, email: string, score: float}>
     */
    protected function matchViaFallback(string $column, string $query, float $threshold, int $limit): Collection
    {
        $queryLower = mb_strtolower($query);

        return User::query()
            ->whereNotNull($column)
            ->limit(1000)
            ->get(['id', 'name', 'email'])
            ->map(function (User $user) use ($column, $queryLower): ?array {
                $value = mb_strtolower((string) $user->{$column});
                similar_text($queryLower, $value, $percent);

                return [
                    'user_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'score' => $percent / 100,
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null && $row['score'] >= $threshold)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }
}
