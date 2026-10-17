<?php

namespace App\Console\Commands\Copilot;

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fase 4b: CLI para evaluar el planner contra un golden set externo.
 *
 * Uso:
 *   php artisan copilot:eval --file=tests/fixtures/copilot_golden.json
 *   php artisan copilot:eval --ci  (exit 1 si accuracy < threshold)
 *
 * Formato JSON esperado:
 * [
 *   {
 *     "prompt": "...",
 *     "snapshot": {},
 *     "expected_intent_family": "...",
 *     "expected_capability_key": "..."
 *   }, ...
 * ]
 */
#[Signature('copilot:eval {--file=tests/fixtures/copilot_golden.json : Path al JSON golden} {--threshold=0.9 : Accuracy minimo (0-1)} {--ci : Retorna exit 1 si accuracy < threshold}')]
#[Description('Evalua el planner del copiloto contra un golden set y reporta accuracy por stage.')]
class EvaluatePlanner extends Command
{
    public function handle(UsersCopilotRequestPlanner $planner): int
    {
        $path = base_path((string) $this->option('file'));

        if (! is_file($path)) {
            $this->error("Golden set no encontrado en {$path}");

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);

        try {
            $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Golden set invalido: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($cases)) {
            $this->error('Golden set debe ser un array JSON.');

            return self::FAILURE;
        }

        $total = count($cases);
        $passed = 0;
        $failures = [];
        $perCapability = [];

        foreach ($cases as $index => $case) {
            $prompt = (string) ($case['prompt'] ?? '');
            $expectedIntent = (string) ($case['expected_intent_family'] ?? '');
            $expectedCapability = (string) ($case['expected_capability_key'] ?? '');

            $snapshot = new CopilotConversationSnapshot(
                is_array($case['snapshot'] ?? null) ? $case['snapshot'] : []
            );

            $plan = $planner->plan($prompt, $snapshot);

            $intentOk = (($plan['intent_family'] ?? null) === $expectedIntent);
            $capabilityOk = (($plan['capability_key'] ?? null) === $expectedCapability);

            $perCapability[$expectedCapability] ??= ['total' => 0, 'passed' => 0];
            $perCapability[$expectedCapability]['total']++;

            if ($intentOk && $capabilityOk) {
                $passed++;
                $perCapability[$expectedCapability]['passed']++;
            } else {
                $failures[] = [
                    'index' => $index,
                    'prompt' => $prompt,
                    'expected' => "{$expectedIntent}/{$expectedCapability}",
                    'got' => sprintf('%s/%s', $plan['intent_family'] ?? 'null', $plan['capability_key'] ?? 'null'),
                ];
            }
        }

        $accuracy = $total === 0 ? 0.0 : $passed / $total;
        $threshold = (float) $this->option('threshold');

        $this->info(sprintf('Evaluated %d cases. Passed: %d. Accuracy: %.2f%%', $total, $passed, $accuracy * 100));

        $this->newLine();
        $this->info('Per-capability breakdown:');
        foreach ($perCapability as $capability => $stats) {
            $rate = $stats['total'] === 0 ? 0 : $stats['passed'] / $stats['total'] * 100;
            $this->line(sprintf('  %-40s %d/%d (%.1f%%)', $capability, $stats['passed'], $stats['total'], $rate));
        }

        if ($failures !== []) {
            $this->newLine();
            $this->warn('Failures:');
            foreach (array_slice($failures, 0, 20) as $failure) {
                $this->line(sprintf('  [%d] "%s" -> expected %s, got %s', $failure['index'], $failure['prompt'], $failure['expected'], $failure['got']));
            }

            if (count($failures) > 20) {
                $this->line(sprintf('  ... and %d more.', count($failures) - 20));
            }
        }

        if ($this->option('ci') && $accuracy < $threshold) {
            $this->error(sprintf('CI gate: accuracy %.2f%% < threshold %.2f%%', $accuracy * 100, $threshold * 100));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
