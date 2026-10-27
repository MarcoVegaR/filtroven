<?php

use Tests\Support\Wix\FixtureCorpus;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeLab;
use Tests\Support\Wix\Prototype\PinTokenProbe;
use Tests\Support\Wix\Prototype\SecurityCheckpointProbe;

it('composes actual admission guarded DNS TLS decoded quota CPU DOM cleanup and a complete retained parent DTO', function (bool $identity) {
    $before = count(scandir('/proc/self/fd'));
    $children = file_get_contents('/proc/self/task/'.getmypid().'/children');
    $lab = new NativeLab;
    try {
        $deadline = hrtime(true) + 4000000000;
        $result = (new SecurityCheckpointProbe)->run($lab, $deadline, $identity);
        $dto = $result['checkpoint_dto'];
        ContractWitness::assert($dto);
        expect($result['supported_topology'])->toBeTrue()->and($result['production_supported'])->toBeFalse()
            ->and($result['network_requests'])->toBe(1)->and($result['decoded_offered'])->toBeGreaterThan(65536)->toBeLessThanOrEqual(81920)
            ->and($result['decoded_retained'])->toBeLessThanOrEqual(65536)->and($result['original_remaining_ms'])->toBeGreaterThan(0)
            ->and($dto['status'])->toBe($identity ? 'partial' : 'failed')->and($dto['resolution'])->toBe($identity ? 'exact' : 'incomplete')
            ->and($dto['meta']['budget_used']['requests'])->toBe($identity ? 3 : 2)->and($dto['meta']['budget_used']['bytes'])->toBe($result['decoded_offered'])
            ->and($dto['meta']['budget_used']['nodes'])->toBe(180010)
            ->and($dto['attempts'][array_key_last($dto['attempts'])]['profile'])->toBe('synthetic-loopback')
            ->and($dto['attempts'][array_key_last($dto['attempts'])]['evidence_refs'])->toBe([])
            ->and($result['reports']['media']['reaped'])->toBeTrue()->and($result['reports']['dimensions']['reason'])->toBe('completed')
            ->and($result['reports']['dimensions']['native_completed'])->toBeTrue();
        expect(array_column($lab->events(), 'kind'))->toBe(['dns', 'dns', 'connect', 'sni', 'http']);
        if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
            fwrite(STDOUT, 'CHECKPOINT_METRIC '.json_encode(['identity' => $identity, 'elapsed_ms' => $result['total_elapsed_ms'],
                'remaining_ms' => $result['original_remaining_ms'], 'requests' => $dto['meta']['budget_used']['requests'],
                'offered' => $result['decoded_offered'], 'retained' => $result['decoded_retained']], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    } finally {
        $lab->close();
    }
    expect(count(scandir('/proc/self/fd')))->toBe($before)
        ->and(file_get_contents('/proc/self/task/'.getmypid().'/children'))->toBe($children);
})->with([true, false]);

it('never exempts the primary WIX destination from private DNS protection', function () {
    $lab = new NativeLab;
    try {
        $url = 'https://www.wixfilters.com/es-co/catalog/results/product.html/51515_wix.html';
        $budget = LookupBudgetProbe::baseline();
        $budget->begin();
        expect(fn () => PinTokenProbe::primary($url, 'es-co', $lab->resolver(), hrtime(true) + 1500000000, 'primary', 0))->toThrow(RuntimeException::class, 'unsafe_dns_address');
        $budget->finish();
        expect($budget->requests)->toBe(1)->and($budget->offered)->toBe(0);
        expect(array_column($lab->events(), 'kind'))->each->toBe('dns');
    } finally {
        $lab->close();
    }
});

it('retains a causal parent DTO and independent DOM after native DNS failure before worker launch', function (string $scenario, bool $identity) {
    $lab = new NativeLab($scenario);
    try {
        $deadline = hrtime(true) + 4000000000;
        $result = (new SecurityCheckpointProbe)->run($lab, $deadline, $identity);
        $dto = $result['checkpoint_dto'];
        ContractWitness::assert($dto);
        expect($dto['status'])->toBe($identity ? 'partial' : 'failed')
            ->and($dto['resolution'])->toBe($identity ? 'exact' : 'incomplete')
            ->and($result['reports']['media']['pid'])->toBeNull()
            ->and($result['reports']['media']['byte_accounting'])->toBe('not_started')
            ->and($result['reports']['media']['dns_queries'])->toBeGreaterThan(0)
            ->and($result['reports']['dimensions']['reason'])->toBe('completed')
            ->and($result['reports']['documents']['reason'])->toBe('dependency_not_started')
            ->and($result['decoded_offered'])->toBe(0)->and($result['decoded_retained'])->toBe(0)
            ->and($result['network_requests'])->toBe(1)->and(hrtime(true))->toBeLessThan($deadline);
        expect(array_column($lab->events(), 'kind'))->each->toBe('dns');
        expect(array_column($dto['diagnostics'], 'code'))->toContain($scenario === 'timeout' ? 'budget_exhausted' : 'transport_error');
    } finally {
        $lab->close();
    }
})->with(['failure', 'mixed', 'truncated', 'timeout'])->with([true, false]);

it('fails closed for unknown deployment topology before DNS child work or a native socket', function () {
    $lab = new NativeLab;
    try {
        $result = (new SecurityCheckpointProbe)->run($lab, hrtime(true) + 3000000000, topology: 'production-unknown');
        ContractWitness::assert($result['dto']);
        expect($result['supported_topology'])->toBeFalse()->and($result['production_supported'])->toBeFalse()
            ->and($result['dto']['resolution'])->toBe('not_attempted')->and($result['network_requests'])->toBe(0)
            ->and($lab->events())->toBe([]);
    } finally {
        $lab->close();
    }
});

it('distinguishes fixed inventory page caps from proof of exhaustive absence', function () {
    $corpus = new FixtureCorpus(dirname(__DIR__, 2).'/fixtures/wix');
    $inventory = $corpus->inventory();
    expect($inventory)->toBeArray();
    $budget = new LookupBudgetProbe(hrtime(true) + 1000000000, maxNodes: 2);
    $budget->visit(2);
    expect(fn () => $budget->visit(1))->toThrow(RuntimeException::class, 'budget_exhausted');
    expect(fn () => $budget->begin())->toThrow(RuntimeException::class, 'budget_exhausted');
    expect($budget->requests)->toBe(0);
});
