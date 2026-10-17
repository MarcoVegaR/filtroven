<?php

use App\Ai\Services\UsersCopilotCapabilityCatalog;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the deterministic planning matrix aligned with canonical capability keys', function () {
    $capabilityKeys = UsersCopilotCapabilityCatalog::keys();
    $deterministicMatrix = config('ai-copilot.planning.matrices.deterministic');
    $extendedMatrix = config('ai-copilot.planning.matrices.extended');

    expect($deterministicMatrix)->not->toBeEmpty()
        ->and($extendedMatrix)->not->toBeEmpty();

    foreach (array_merge($deterministicMatrix, $extendedMatrix) as $case) {
        $capabilityKey = $case['capability_key'] ?? null;

        if (is_string($capabilityKey)) {
            expect($capabilityKeys)->toContain($capabilityKey);
        }
    }
});
