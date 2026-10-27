<?php

use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\DestinationPolicyProbe;
use Tests\Support\Wix\Prototype\LookupBudgetProbe;
use Tests\Support\Wix\Prototype\NativeLab;
use Tests\Support\Wix\Prototype\NativeNavigationProbe;
use Tests\Support\Wix\Prototype\ParentResultProbe;
use Tests\Support\Wix\Prototype\SupervisedPhpTask;
use Tests\Support\Wix\Prototype\SupervisedReceiveProbe;

it('keeps the 20s 3s 8s 13 sends serial 2MiB 8MiB zero-recovery evaluation baseline explicit', function () {
    $before = hrtime(true);
    $budget = LookupBudgetProbe::baseline();
    expect($budget->deadline - $before)->toBeGreaterThanOrEqual(20000000000)->toBeLessThan(20100000000)
        ->and($budget->maxRequests)->toBe(13)->and($budget->maxResponse)->toBe(2097152)->and($budget->maxTotal)->toBe(8388608)
        ->and($budget->maxRedirects)->toBe(0)->and($budget->maxRetries)->toBe(0);
    $budget->begin();
    expect($budget->requestDeadline - hrtime(true))->toBeLessThanOrEqual(8000000000)
        ->and($budget->connectDeadline - hrtime(true))->toBeLessThanOrEqual(3000000000);
    expect(fn () => $budget->begin())->toThrow(RuntimeException::class);
    $budget->finish();
});

it('predebits all 4 CO 4 AP 1 detail 4 metadata sends and refuses a second four-page collection before wire', function () {
    $lab = new NativeLab;
    try {
        $budget = LookupBudgetProbe::baseline();
        foreach (['co' => 4, 'ap' => 4, 'detail' => 1, 'metadata' => 4] as $stage => $pages) {
            foreach (range(1, $pages) as $page) {
                $result = (new NativeNavigationProbe)->run($lab, '/'.$stage, $budget);
                expect($result['response']['status'])->toBe(200);
                $budget->visit(1);
            }
        }
        expect($budget->requests)->toBe(13)->and($budget->nodes)->toBe(13)->and($budget->offered)->toBe(117);
        $events = $lab->events();
        expect(fn () => (new NativeNavigationProbe)->run($lab, '/secondcollection', $budget))->toThrow(RuntimeException::class, 'budget_exhausted');
        expect($lab->events())->toBe($events)
            ->and(count(array_filter($events, fn (array $e): bool => $e['kind'] === 'http')))->toBe(13);
    } finally {
        $lab->close();
    }
});

it('revalidates repins and debits a manual TLS hop only under an explicit experimental quota', function () {
    $lab = new NativeLab;
    try {
        $default = LookupBudgetProbe::baseline();
        $stopped = (new NativeNavigationProbe)->run($lab, '/redirect', $default);
        expect($stopped['response']['status'])->toBe(302)->and($default->requests)->toBe(1)->and($default->redirects)->toBe(0);
        $budget = new LookupBudgetProbe(hrtime(true) + 4000000000, maxRedirects: 1);
        $result = (new NativeNavigationProbe)->run($lab, '/redirect', $budget);
        expect($result['response']['status'])->toBe(200)->and(count($result['history']))->toBe(2)
            ->and($budget->requests)->toBe(2)->and($budget->redirects)->toBe(1);
        $events = $lab->events();
        expect(count(array_filter($events, fn (array $e): bool => $e['kind'] === 'dns')))->toBe(6)
            ->and(count(array_filter($events, fn (array $e): bool => $e['kind'] === 'sni' && $e['name'] === 'runtime.test')))->toBe(3);
    } finally {
        $lab->close();
    }
});

it('does not connect to a forbidden manual hop or loop and does not invent supported zero-redirect navigation', function (string $path) {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 4000000000, maxRedirects: 1);
        expect(fn () => (new NativeNavigationProbe)->run($lab, $path, $budget))->toThrow(RuntimeException::class, 'transport_error');
        expect($budget->requests)->toBe(1)->and(count(array_filter($lab->events(), fn (array $e): bool => $e['kind'] === 'http')))->toBe(1);
    } finally {
        $lab->close();
    }
})->with(['/forbidden', '/loop']);

it('retries an idempotent classified transient failure with history only when its experimental quota permits', function () {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 4000000000, maxRetries: 1);
        $result = (new NativeNavigationProbe)->run($lab, '/retry', $budget);
        expect($result['recovered'])->toBeTrue()->and(array_column($result['history'], 'status'))->toBe([503, 200])
            ->and($budget->requests)->toBe(2)->and($budget->retries)->toBe(1);
        $dto = ParentResultProbe::snapshot('exact');
        $dto['diagnostics'][] = ['code' => 'http_error', 'stage' => 'synthetic-retry', 'attempt_id' => 'a-detail', 'section' => null,
            'severity' => 'warning', 'recovered' => true, 'message' => 'SYNTHETIC transient 503 recovered within shared budget.', 'evidence_refs' => []];
        ContractWitness::assert($dto);
        expect($dto['diagnostics'][array_key_last($dto['diagnostics'])]['recovered'])->toBeTrue();
    } finally {
        $lab->close();
    }
});

it('does not reset the deadline for backoff fallback or region and does not turn lack of time into absence', function () {
    $lab = new NativeLab;
    try {
        $budget = new LookupBudgetProbe(hrtime(true) + 450000000);
        $deadline = $budget->deadline;
        expect(fn () => $budget->wait(1000))->toThrow(RuntimeException::class);
        usleep(210000);
        expect(fn () => (new NativeNavigationProbe)->run($lab, '/ap', $budget))->toThrow(RuntimeException::class, 'budget_exhausted');
        expect($budget->deadline)->toBe($deadline)->and($budget->requests)->toBe(0)->and($lab->events())->toBe([]);
        $dto = ParentResultProbe::snapshot('incomplete');
        ContractWitness::assert($dto);
        expect($dto['resolution'])->toBe('incomplete')->and($dto['coverage']['complete'])->toBeFalse();
    } finally {
        $lab->close();
    }
});

it('preserves the parent contract and safe independent work after a real native receive quota failure', function (bool $identity) {
    $lab = new NativeLab;
    try {
        $deadline = hrtime(true) + 4000000000;
        $budget = new LookupBudgetProbe($deadline, maxResponse: 65536);
        $result = (new ParentResultProbe)->run(['media' => 'native_receive', 'documents' => 'echo', 'dimensions' => 'echo'], $deadline,
            unitTimeoutMs: 1500, identityConfirmed: $identity,
            unitExecutor: function (string $section, string $operation, int $unitDeadline, ?Closure $cancel) use ($lab, $budget): array {
                if ($section === 'media') {
                    return (new SupervisedReceiveProbe)->run($lab, '/gzip', $budget, $unitDeadline, $cancel);
                }
                $budget->visit(1);

                return (new SupervisedPhpTask)->run(['operation' => 'echo', 'payload' => 'synthetic-independent', 'units' => 1], $unitDeadline, $cancel);
            });
        ContractWitness::assert($result['dto']);
        expect($result['dto']['resolution'])->toBe($identity ? 'exact' : 'incomplete')
            ->and($result['dto']['status'])->toBe($identity ? 'partial' : 'failed')
            ->and($result['reports']['media']['reason'])->toBe('deadline')->and($result['reports']['media']['reaped'])->toBeTrue()
            ->and($result['reports']['documents']['reason'])->toBe('dependency_not_started')
            ->and($result['reports']['dimensions']['reason'])->toBe('completed')->and($result['remaining_ms'])->toBeGreaterThan(0)
            ->and($budget->requests)->toBe(1)->and($budget->nodes)->toBe(1);
    } finally {
        $lab->close();
    }
})->with([true, false]);

it('validates relative primary-profile navigation without trusting embedded authorities', function () {
    $policy = new DestinationPolicyProbe;
    $base = 'https://www.wixfilters.com/es-co/catalog/results/product.html/a.html';
    expect($policy->redirect($base, 'b.html', 'es-co'))->toBe('https://www.wixfilters.com/es-co/catalog/results/product.html/b.html');
    foreach (['//evil.test/x', 'https://127.0.0.1/x', '/en-ap/catalog/results/product.html/x.html', '../x', '/bin/assets.json?url=evil'] as $bad) {
        expect(fn () => $policy->redirect($base, $bad, 'es-co'))->toThrow(RuntimeException::class);
    }
});
