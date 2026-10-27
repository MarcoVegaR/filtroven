<?php

use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\ParentResultProbe;
use Tests\Support\Wix\Prototype\SupervisedPhpTask;

/** Local opt-in empirical evidence, never WIX publication or a production log. */
function wixParentMeasurement(string $case, array $report): void
{
    if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
        unset($report['dto']);
        foreach ($report['reports'] ?? [] as $section => $unit) {
            unset($report['reports'][$section]['message']);
        }
        fwrite(STDOUT, 'PARENT_METRIC '.json_encode(['case' => $case, 'report' => $report], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

it('validates every complete contract snapshot without a production DTO or parser', function (string $scenario, string $pair) {
    $dto = ParentResultProbe::snapshot($scenario);
    expect(fn () => ContractWitness::assertRetained($dto, ParentResultProbe::snapshot($scenario)))->not->toThrow(LogicException::class);
    expect($dto['status'].'/'.$dto['resolution'])->toBe($pair)
        ->and($dto['product'] !== null)->toBe($dto['resolution'] === 'exact')
        ->and(array_keys($dto['coverage']['sections']))->toBe(ContractWitness::SECTIONS);
    if ($dto['product'] !== null) {
        expect(array_keys($dto['product']))->toBe(ContractWitness::SECTIONS);
        $raw = $dto['product']['observations'];
        expect(array_column($raw, 'value_raw'))->toBe([null, null, '', 'true', 'true', '123456789012345678901234567890.003', '{"id":"001"}', '["001","001"]'])
            ->and($dto['product']['applications'][0]['navigation']['params'])->toBe([['name' => 'id', 'value' => '01'], ['name' => 'id', 'value' => '02']]);
    }
})->with([
    ['exact', 'success/exact'], ['partial', 'partial/exact'], ['negative', 'success/no_exact_match'],
    ['ambiguous', 'failed/ambiguous'], ['incomplete', 'failed/incomplete'], ['not_attempted', 'failed/not_attempted'],
    ['conflict', 'failed/incomplete'], ['unverified', 'failed/incomplete'],
]);

it('rejects omitted fields throughout every nonempty contract object not just the envelope', function () {
    $seed = ParentResultProbe::snapshot();
    $paths = [];
    $walk = function (array $value, array $path = []) use (&$walk, &$paths): void {
        foreach ($value as $key => $item) {
            $next = [...$path, $key];
            if (is_string($key) && ! in_array($key, ['es-co', 'en-ap', 'g-identity', 'h-identity', 'h-sections'], true)) {
                $paths[] = $next;
            }
            if (is_array($item)) {
                $walk($item, $next);
            }
        }
    };
    $walk($seed);
    expect(count($paths))->toBe(308);
    foreach ($paths as $path) {
        $dto = $seed;
        $node = &$dto;
        foreach (array_slice($path, 0, -1) as $key) {
            $node = &$node[$key];
        }
        unset($node[$path[array_key_last($path)]]);
        unset($node);
        expect(fn () => ContractWitness::assert($dto))->toThrow(LogicException::class);
    }
});

it('rejects contract contradictions and corrupted leaf types', function (Closure $mutate) {
    $dto = ParentResultProbe::snapshot('partial');
    $mutate($dto);
    expect(fn () => ContractWitness::assert($dto))->toThrow(LogicException::class);
})->with([
    'version number' => [function (array &$d): void {
        $d['contract_version'] = 1.0;
    }],
    'alias evidence registry' => [function (array &$d): void {
        $d['evidence_registry'] = $d['evidence'];
    }],
    'invalid pair' => [function (array &$d): void {
        $d['resolution'] = 'no_exact_match';
    }],
    'product lost' => [function (array &$d): void {
        $d['product'] = null;
    }],
    'source lost' => [function (array &$d): void {
        $d['source'] = null;
    }],
    'literal cast' => [function (array &$d): void {
        $d['product']['identity']['code'] = '51515';
    }],
    'list null' => [function (array &$d): void {
        $d['product']['documents'] = null;
    }],
    'identifier cast' => [function (array &$d): void {
        $d['product']['identity']['identifiers'][0]['value'] = 51515;
    }],
    'raw number cast' => [function (array &$d): void {
        $d['product']['observations'][5]['value_raw'] = 123.0;
    }],
    'number lexical loss' => [function (array &$d): void {
        $d['product']['observations'][5]['value_raw'] = '001';
    }],
    'missing presence' => [function (array &$d): void {
        $d['product']['observations'][0]['value_presence'] = 'present';
    }],
    'null empty confusion' => [function (array &$d): void {
        $d['product']['observations'][1]['value_raw'] = '';
    }],
    'boolean typo' => [function (array &$d): void {
        $d['product']['observations'][3]['value_raw'] = 'TRUE';
    }],
    'object flattened' => [function (array &$d): void {
        $d['product']['observations'][6]['value_raw'] = '001';
    }],
    'raw type new enum' => [function (array &$d): void {
        $d['product']['observations'][0]['raw_type'] = 'date';
    }],
    'negative order' => [function (array &$d): void {
        $d['product']['dimensions'][0]['order'] = -1;
    }],
    'cell omitted' => [function (array &$d): void {
        unset($d['product']['applications'][0]['cells'][0]['unit_raw']);
    }],
    'period cast' => [function (array &$d): void {
        $d['product']['applications'][0]['period_raw'] = 9999;
    }],
    'navigation map' => [function (array &$d): void {
        $d['product']['applications'][0]['navigation']['params'] = ['id' => '01'];
    }],
    'no occurrences' => [function (array &$d): void {
        $d['product']['applications'][0]['occurrences'] = [];
    }],
    'raw metadata bool cast' => [function (array &$d): void {
        $d['product']['documents'][0]['declared_metadata'][0]['value_raw'] = true;
    }],
    'orphan projection' => [function (array &$d): void {
        $d['product']['dimensions'][0]['evidence_refs'] = ['missing'];
    }],
    'orphan evidence attempt' => [function (array &$d): void {
        $d['evidence']['h-sections']['attempt_id'] = 'absent';
    }],
    'wrong evidence profile' => [function (array &$d): void {
        $d['evidence']['h-sections']['profile'] = 'en-ap';
    }],
    'wrong source attribution' => [function (array &$d): void {
        $d['source']['evidence_refs'] = ['g-identity'];
    }],
    'source url unrelated' => [function (array &$d): void {
        $d['source']['url'] .= '?invented';
    }],
    'unchanged final url' => [function (array &$d): void {
        $d['evidence']['g-identity']['final_url'] = $d['evidence']['g-identity']['source_url'];
    }],
    'timestamp local' => [function (array &$d): void {
        $d['evidence']['g-identity']['captured_at'] = '2026-10-24T00:00:00+00:00';
    }],
    'timestamp impossible' => [function (array &$d): void {
        $d['meta']['returned_at'] = '2026-99-99T00:00:00Z';
    }],
    'excerpt object' => [function (array &$d): void {
        $d['evidence']['g-identity']['raw_excerpt'] = [];
    }],
    'no context' => [function (array &$d): void {
        $d['evidence']['g-identity']['locator'] = '';
    }],
    'unsafe source' => [function (array &$d): void {
        $d['evidence']['g-identity']['source_url'] = 'file:///etc/passwd';
    }],
    'attempt duplicated' => [function (array &$d): void {
        $d['attempts'][] = $d['attempts'][0];
    }],
    'account reset' => [function (array &$d): void {
        $d['meta']['budget_used']['requests'] = 0;
    }],
    'negative budget' => [function (array &$d): void {
        $d['attempts'][0]['budget_used']['bytes'] = -1;
    }],
    'page cast' => [function (array &$d): void {
        $d['attempts'][0]['pages'] = ['1'];
    }],
    'no identity stages' => [function (array &$d): void {
        $d['product']['identity']['evidence_refs'] = ['h-identity'];
    }],
    'coverage new enum' => [function (array &$d): void {
        $d['coverage']['sections']['media']['state'] = 'empty';
    }],
    'failed complete' => [function (array &$d): void {
        $d['coverage']['sections']['media']['complete'] = true;
    }],
    'group dishonest' => [function (array &$d): void {
        $d['coverage']['groups']['resources']['complete'] = true;
    }],
    'coverage key missing' => [function (array &$d): void {
        unset($d['coverage']['sections']['observations']);
    }],
    'partial without terminal' => [function (array &$d): void {
        $d['diagnostics'] = [];
    }],
    'terminal recovered' => [function (array &$d): void {
        $d['diagnostics'][0]['recovered'] = true;
    }],
    'invented code' => [function (array &$d): void {
        $d['diagnostics'][0]['code'] = 'cancelled';
    }],
    'nullable omitted' => [function (array &$d): void {
        unset($d['diagnostics'][0]['attempt_id']);
    }],
    'diagnostic wrong attempt' => [function (array &$d): void {
        $d['diagnostics'][0]['attempt_id'] = 'a-search';
    }],
    'severity cast' => [function (array &$d): void {
        $d['diagnostics'][0]['severity'] = false;
    }],
]);

it('preserves optional source keys raw lexemes and repeated records through serialization', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['product']['observations'][0]['source_key'] = null;
    $dto['product']['observations'][1]['source_key'] = 'published_null';
    $json = json_encode($dto, JSON_THROW_ON_ERROR);
    $copy = json_decode($json, true, 20, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    ContractWitness::assert($copy, $dto['evidence']);
    expect($copy)->toBe($dto);
    $copy['evidence']['h-sections']['raw_excerpt'] = 'hash-only';
    expect(fn () => ContractWitness::assert($copy, $dto['evidence']))->toThrow(LogicException::class, 'retained source proof');
    $copy = $dto;
    $copy['product']['dimensions'][0]['value_raw'] = '132.003';
    expect(fn () => ContractWitness::assertRetained($copy, $dto))->toThrow(LogicException::class, 'retained raw material');
    $copy = $dto;
    $copy['attempts'][0]['budget_used']['bytes']++;
    expect(fn () => ContractWitness::assertRetained($copy, $dto))->toThrow(LogicException::class, 'retained attempts');
});

it('reaps interrupted native work inside measured operation brackets without pcntl', function (string $operation) {
    $baselineFd = glob('/proc/self/fd/*');
    $childrenPath = '/proc/self/task/'.getmypid().'/children';
    $baselineChildren = file_get_contents($childrenPath);
    $result = (new SupervisedPhpTask)->run(['operation' => $operation, 'payload' => '', 'units' => 1], hrtime(true) + 4000000000,
        fn (array $report): bool => $report['native_started'] && ! $report['native_completed'] && $report['native_start_cpu_ticks'] !== null && $report['native_cpu_ticks'] > $report['native_start_cpu_ticks']);
    wixParentMeasurement($operation, ['reports' => [$operation => $result]]);
    expect($result['reason'])->toBe('cancelled')->and($result['native_started'])->toBeTrue()->and($result['native_completed'])->toBeFalse()
        ->and($result['native_cpu_ticks'])->toBeGreaterThan($result['native_start_cpu_ticks'])
        ->and($result['reaped'])->toBeTrue()->and($result['pipes_closed'])->toBeTrue()->and($result['signal'])->toBe(15)
        ->and($result['overshoot_ms'])->toBe(0.0)->and(file_exists('/proc/'.$result['pid']))->toBeFalse()
        ->and(glob('/proc/self/fd/*'))->toBe($baselineFd)->and(file_get_contents($childrenPath))->toBe($baselineChildren);
})->with(['native_cpu', 'native_dom', 'native_json', 'native_xpath']);

it('returns a fully validated parent DTO after local failure and continues independent units', function (string $failure, bool $identity) {
    $baseline = ParentResultProbe::snapshot($identity ? 'exact' : 'incomplete');
    $deadline = hrtime(true) + 4000000000;
    $result = (new ParentResultProbe)->run(['media' => $failure, 'documents' => 'echo', 'dimensions' => 'echo', 'oe_references' => 'echo'], $deadline, 700, $identity);
    wixParentMeasurement($failure.($identity ? '-post' : '-pre'), $result);
    $dto = $result['dto'];
    ContractWitness::assert($dto, $baseline['evidence']);
    expect($dto['status'].'/'.$dto['resolution'])->toBe($identity ? 'partial/exact' : 'failed/incomplete')
        ->and($dto['diagnostics'][0]['code'])->toBe($failure === 'crash' ? 'parse_error' : 'budget_exhausted')
        ->and($dto['source'])->toBe($baseline['source'])->and($dto['evidence'])->toBe($baseline['evidence'])
        ->and($dto['attempts'])->toBe($baseline['attempts'])
        ->and($result['reports']['media']['reaped'])->toBeTrue()->and($result['reports']['media']['pipes_closed'])->toBeTrue()
        ->and($result['reports']['documents']['pid'])->toBeNull()
        ->and($result['reports']['dimensions']['reason'])->toBe('completed')->and($result['reports']['oe_references']['reason'])->toBe('completed')
        ->and($result['build_validate_serialize_ms'])->toBeLessThan($result['reserved_ms'])
        ->and($result['overshoot_ms'])->toBe(0.0)->and(hrtime(true))->toBeLessThan($deadline);
    if ($identity) {
        expect($dto['product']['identity'])->toBe($baseline['product']['identity'])
            ->and($dto['product']['dimensions'])->toBe($baseline['product']['dimensions'])
            ->and($dto['product']['observations'])->toBe($baseline['product']['observations'])
            ->and($dto['coverage']['sections']['dimensions'])->toBe($baseline['coverage']['sections']['dimensions']);
    }
    if ($failure === 'native_cpu') {
        expect($result['reports']['media']['native_started'])->toBeTrue()->and($result['reports']['media']['native_completed'])->toBeFalse()
            ->and($result['reports']['media']['reason'])->toBe('deadline');
    }
    if ($failure === 'stopped') {
        expect($result['reports']['media']['signal'])->toBe(9);
    }
})->with([['hang', true], ['crash', true], ['hang', false], ['crash', false], ['native_cpu', true], ['native_cpu', false], ['stopped', true], ['stopped', false]]);

it('does not invent budget exhaustion when all preidentity units completed', function () {
    $result = (new ParentResultProbe)->run(['dimensions' => 'echo'], hrtime(true) + 2000000000, 700, false);
    expect($result['reports']['dimensions']['reason'])->toBe('completed')
        ->and($result['dto']['status'].'/'.$result['dto']['resolution'])->toBe('failed/incomplete')
        ->and($result['dto']['diagnostics'][0]['code'])->toBe('identity_unverified')
        ->and($result['dto']['product'])->toBeNull()->and($result['dto']['source'])->toBeNull();
});

it('retains explicit synthetic raw material rather than only a generic type summary', function () {
    $dto = ParentResultProbe::snapshot();
    expect($dto['evidence']['h-sections']['raw_excerpt'])->toContain(
        '123456789012345678901234567890.003', '{"id":"001"}', '["001","001"]',
        '/SYNTHETIC/not-downloaded', 'image/tiff', 'TIFF', '0001', 'id=01&id=02', 'mainImage="true"',
    );
});

it('does not claim a valid unique identity in synthetic conflict or missing-id evidence', function (string $scenario, string $outcome, string $proof) {
    $dto = ParentResultProbe::snapshot($scenario);
    expect($dto['attempts'][0]['outcome'])->toBe($outcome)
        ->and($dto['evidence']['g-identity']['raw_excerpt'])->toContain($proof)
        ->and($dto['evidence']['g-identity']['raw_excerpt'])->not->toContain('unique WIX');
})->with([
    ['ambiguous', 'ambiguous_candidates', 'two distinct valid exact identities'],
    ['conflict', 'identity_conflict', 'sku=SYNTHETIC-A versus sku=SYNTHETIC-B'],
    ['unverified', 'identity_unverified', 'required sku and urlKey missing'],
]);

it('cancels one native unit yet preserves the ledger and continues only with original resources', function (string $operation, bool $identity) {
    $baseline = ParentResultProbe::snapshot($identity ? 'exact' : 'incomplete');
    $result = (new ParentResultProbe)->run(['media' => $operation, 'dimensions' => 'echo'], hrtime(true) + 4000000000, 2500, $identity,
        fn (array $report): bool => $report['native_started'] && $report['native_cpu_ticks'] > ($report['native_start_cpu_ticks'] ?? PHP_INT_MAX));
    wixParentMeasurement('cancel-parent-'.$operation.($identity ? '-post' : '-pre'), $result);
    expect($result['reports']['media']['reason'])->toBe('cancelled')->and($result['reports']['dimensions']['reason'])->toBe('completed')
        ->and($result['reports']['media']['native_started'])->toBeTrue()->and($result['reports']['media']['native_completed'])->toBeFalse()
        ->and($result['reports']['media']['native_cpu_ticks'])->toBeGreaterThan($result['reports']['media']['native_start_cpu_ticks'])
        ->and($result['dto']['status'])->toBe($identity ? 'partial' : 'failed')->and($result['dto']['diagnostics'][0]['code'])->toBe('budget_exhausted')
        ->and($result['dto']['attempts'])->toBe($baseline['attempts'])->and($result['dto']['evidence'])->toBe($baseline['evidence'])
        ->and($result['dto']['source'])->toBe($baseline['source'])->and($result['remaining_units'])->toBe(14)->and($result['overshoot_ms'])->toBe(0.0);
})->with([['native_cpu', true], ['native_dom', true], ['native_json', true], ['native_xpath', true], ['native_cpu', false], ['native_dom', false], ['native_json', false], ['native_xpath', false]]);

it('stops globally without granting a fresh unit or fallback budget and includes result construction in D', function () {
    $deadline = hrtime(true) + 1200000000;
    $result = (new ParentResultProbe)->run(['media' => 'hang', 'dimensions' => 'echo', 'oe_references' => 'echo'], $deadline, 4000);
    wixParentMeasurement('global-exhaustion', $result);
    expect($result['reports']['media']['reason'])->toBe('deadline')->and($result['reports']['dimensions']['pid'])->toBeNull()
        ->and($result['reports']['oe_references']['pid'])->toBeNull()->and($result['dto']['scope']['consulted_profiles'])->toBe(['es-co'])
        ->and($result['dto']['attempts'])->toHaveCount(2)->and($result['remaining_units'])->toBe(15)
        ->and($result['build_validate_serialize_ms'])->toBeLessThan($result['reserved_ms'])
        ->and($result['remaining_ms'])->toBeGreaterThan(0)->and($result['overshoot_ms'])->toBe(0.0)->and(hrtime(true))->toBeLessThan($deadline);
    foreach ($result['reports'] as $report) {
        expect($report['absolute_deadline_ns'])->toBeLessThanOrEqual($deadline - ParentResultProbe::FINALIZATION_RESERVE_NS)
            ->and($report['global_deadline_ns'])->toBe($deadline);
    }
    $noUnits = (new ParentResultProbe)->run(['media' => 'echo', 'dimensions' => 'echo'], hrtime(true) + 2000000000, 700, true, null, 0);
    expect(array_column($noUnits['reports'], 'pid'))->toBe([null, null])->and($noUnits['remaining_units'])->toBe(0);
});

it('fails preflight before any child and exposes a late comparable identity contradiction', function () {
    $result = (new ParentResultProbe('/no/php'))->run(['media' => 'echo'], hrtime(true) + 2000000000);
    expect($result['dto']['resolution'])->toBe('not_attempted')->and($result['dto']['attempts'])->toBe([])->and($result['reports'])->toBe([])
        ->and($result['json_evidence_is_object'])->toBeTrue();
    $baseline = ParentResultProbe::snapshot('partial');
    $dto = ParentResultProbe::invalidate($baseline);
    ContractWitness::assert($dto, $baseline['evidence']);
    expect($dto['product'])->toBeNull()->and($dto['source'])->toBeNull()->and($dto['resolution'])->toBe('incomplete')
        ->and($dto['coverage']['sections']['identity']['state'])->toBe('failed')->and($dto['coverage']['complete'])->toBeFalse()
        ->and($dto['attempts'][0])->toBe($baseline['attempts'][0])->and($dto['evidence']['h-conflict']['raw_excerpt'])->toContain('SYNTHETIC-B');
});

it('validates all coverage meanings without conflating missing empty pending or unsupported', function (string $state, bool $complete) {
    $dto = ParentResultProbe::snapshot();
    $dto['product']['media'] = [];
    $dto['evidence']['h-resources'] = $dto['evidence']['h-sections'];
    $dto['evidence']['h-resources']['locator'] = 'recognized-main:synthetic-gallery';
    $dto['evidence']['h-resources']['raw_excerpt'] = 'SYNTHETIC required gallery inspection result='.$state.'; finite inventory, no additional required pages.';
    $dto['attempts'][1]['evidence_refs'][] = 'h-resources';
    $dto['coverage']['sections']['media'] = ['state' => $state, 'complete' => $complete, 'reasons' => $complete ? [] : ['SYNTHETIC required surface remains incomplete.'], 'evidence_refs' => $state === 'not_checked' ? [] : ['h-resources']];
    $dto['coverage']['groups']['resources']['complete'] = $complete;
    $dto['coverage']['complete'] = $complete;
    if (! $complete) {
        $dto['status'] = 'partial';
        $dto['diagnostics'] = [['code' => $state === 'unsupported' ? 'unsupported_section' : 'parse_error', 'stage' => 'synthetic-gallery', 'attempt_id' => 'a-detail', 'severity' => 'error', 'recovered' => false, 'message' => 'SYNTHETIC required surface remains incomplete.', 'section' => 'media', 'evidence_refs' => $state === 'not_checked' ? [] : ['h-resources']]];
    }
    expect(fn () => ContractWitness::assertRetained($dto, $dto))->not->toThrow(LogicException::class)
        ->and($dto['coverage']['groups']['technical']['complete'])->toBeTrue();
})->with([['captured', false], ['published_empty', true], ['not_published', true], ['failed', false], ['not_checked', false], ['unsupported', false]]);

it('accepts recovered warnings without changing enums and rejects a falsely exhaustive negative', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['product']['media'][0]['position_published'] = -1;
    $dto['diagnostics'] = [['code' => 'transport_error', 'stage' => 'synthetic-optional', 'attempt_id' => null, 'severity' => 'warning', 'recovered' => true, 'message' => 'SYNTHETIC classified failure recovered within the same budget.', 'section' => null, 'evidence_refs' => []]];
    expect(fn () => ContractWitness::assert($dto))->not->toThrow(LogicException::class);
    $dto['meta']['requested_at'] = '2026-10-24T00:00:00.000Z';
    $dto['meta']['returned_at'] = '2026-10-24T00:00:00.123456789Z';
    expect(fn () => ContractWitness::assert($dto))->not->toThrow(LogicException::class);
    $dto['meta']['requested_at'] = '2026-10-24T00:00:00.123456790Z';
    expect(fn () => ContractWitness::assert($dto))->toThrow(LogicException::class, 'result chronology');
    $dto = ParentResultProbe::snapshot('negative');
    $dto['scope']['exhaustive_profiles'] = ['es-co'];
    expect(fn () => ContractWitness::assert($dto))->toThrow(LogicException::class, 'negative proof');
    $dto = ParentResultProbe::snapshot('negative');
    $dto['attempts'][1]['outcome'] = 'failed';
    expect(fn () => ContractWitness::assert($dto))->toThrow(LogicException::class, 'negative proof');
});

it('keeps programming errors visible while closing owned children and descriptors', function () {
    $childrenPath = '/proc/self/task/'.getmypid().'/children';
    $children = file_get_contents($childrenPath);
    $descriptors = glob('/proc/self/fd/*');
    expect(fn () => (new ParentResultProbe)->run(['media' => 'native_cpu'], hrtime(true) + 3000000000, 2000, true,
        function (array $report): bool {
            if ($report['native_started']) {
                throw new LogicException('Synthetic caller bug must remain visible.');
            }

            return false;
        }))->toThrow(LogicException::class, 'Synthetic caller bug must remain visible.');
    expect(file_get_contents($childrenPath))->toBe($children)->and(glob('/proc/self/fd/*'))->toBe($descriptors);
});

it('does not discard an already queued native completion marker during cancellation cleanup', function () {
    $result = (new SupervisedPhpTask)->run(['operation' => 'native_json', 'payload' => '', 'units' => 1], hrtime(true) + 4000000000,
        function (array $report): bool {
            if ($report['native_started']) {
                usleep(200000);

                return true;
            }

            return false;
        });
    wixParentMeasurement('queued-native-end-counterexample', ['reports' => ['native_json' => $result]]);
    expect($result['reason'])->toBe('cancelled')->and($result['native_started'])->toBeTrue()->and($result['native_completed'])->toBeTrue()
        ->and($result['stderr_bytes'])->toBe(strlen("BEGIN:native_json\nEND:native_json\n"))
        ->and($result['reaped'])->toBeTrue()->and($result['pipes_closed'])->toBeTrue();
});
