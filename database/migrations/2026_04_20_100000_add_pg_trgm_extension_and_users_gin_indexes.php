<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4a: pg_trgm + indices GIN en users para matching tolerante.
 *
 * Solo se aplica en PostgreSQL. En SQLite (usado por tests unitarios) la
 * migracion es no-op para preservar compatibilidad.
 *
 * Los indices GIN aceleran:
 * - `similarity(users.name, :q)` para busqueda tolerante por nombre.
 * - `similarity(users.email, :q)` para busqueda tolerante por email.
 * - `users.name ILIKE '%...%'` con operator_class gin_trgm_ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS users_name_trgm_idx ON users USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_email_trgm_idx ON users USING gin (email gin_trgm_ops)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS users_email_trgm_idx');
        // Nota: no se dropea pg_trgm porque podria usarse en otros contextos.
    }

    protected function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
