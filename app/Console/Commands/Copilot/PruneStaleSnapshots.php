<?php

namespace App\Console\Commands\Copilot;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Fase 1c: Limpia snapshots semanticos antiguos segun retention_days.
 *
 * - NO borra la conversacion ni sus mensajes (preserva auditoria).
 * - Solo vacia `snapshot`, `snapshot_version`, `last_turn_at` de filas con
 *   `last_turn_at < now() - retention_days`.
 */
#[Signature('copilot:prune-snapshots {--dry-run : Solo reporta conteos sin modificar datos}')]
#[Description('Vacia los snapshots semanticos del copiloto mas antiguos que el retention configurado.')]
class PruneStaleSnapshots extends Command
{
    public function handle(DatabaseManager $database): int
    {
        $retentionDays = (int) config('ai-copilot.snapshot.retention_days', 30);
        $threshold = CarbonImmutable::now()->subDays($retentionDays);

        $query = $database->table('agent_conversations')
            ->whereNotNull('last_turn_at')
            ->where('last_turn_at', '<', $threshold);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No hay snapshots para podar (retention '.$retentionDays.'d).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d snapshots se podarian (retention %dd).', $total, $retentionDays));

            return self::SUCCESS;
        }

        $affected = $query->update([
            'snapshot' => null,
            'snapshot_version' => null,
            'last_turn_at' => null,
            'updated_at' => now(),
        ]);

        $this->info(sprintf('Podados %d snapshots (retention %dd).', $affected, $retentionDays));

        return self::SUCCESS;
    }
}
