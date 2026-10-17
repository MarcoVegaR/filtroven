<?php

use Illuminate\Support\Facades\File;

/**
 * Fase 4b: Verifica el CLI copilot:eval contra un golden set real.
 */
it('reports 100 percent accuracy on a matching golden set', function () {
    $this->artisan('copilot:eval', ['--file' => 'tests/fixtures/copilot_golden.json'])
        ->expectsOutputToContain('Accuracy: 100.00%')
        ->assertExitCode(0);
});

it('fails CI gate when accuracy falls below the threshold', function () {
    $fixturePath = base_path('tests/fixtures/copilot_golden_failing.json');
    File::put($fixturePath, json_encode([
        [
            'prompt' => 'cuantos usuarios hay',
            'snapshot' => [],
            'expected_intent_family' => 'read_metrics',
            'expected_capability_key' => 'users.metrics.inactive', // wrong on purpose
        ],
    ]));

    $this->artisan('copilot:eval', [
        '--file' => 'tests/fixtures/copilot_golden_failing.json',
        '--ci' => true,
        '--threshold' => '0.9',
    ])->assertExitCode(1);

    File::delete($fixturePath);
});

it('fails gracefully when golden set file does not exist', function () {
    $this->artisan('copilot:eval', ['--file' => 'tests/fixtures/nonexistent.json'])
        ->expectsOutputToContain('Golden set no encontrado')
        ->assertExitCode(1);
});
