<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1c: Staleness semantics.
 *
 * Anade `last_turn_at` para que el planner pueda distinguir entre:
 * - fresh: turn reciente, continuation automatica permitida.
 * - stale: entre soft y hard TTL, requiere confirmacion explicita del usuario.
 * - expired: sobre hard TTL, se ignora el snapshot.
 *
 * Un indice ayuda al job de retention que barre snapshots por encima del
 * retention_days configurado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->timestamp('last_turn_at')->nullable()->after('snapshot_version');
            $table->index('last_turn_at', 'agent_conversations_last_turn_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('agent_conversations_last_turn_at_index');
            $table->dropColumn('last_turn_at');
        });
    }
};
