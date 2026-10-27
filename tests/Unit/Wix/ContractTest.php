<?php

use App\Support\Wix\Data\WixLookupResult;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\ParentResultProbe;

it('round trips the independent complete contract witness through typed production data', function (string $scenario) {
    $original = ParentResultProbe::snapshot($scenario);
    $result = WixLookupResult::fromArray($original);
    expect($result->toArray())->toEqual($original);
    ContractWitness::assert($result->toArray(), $original['evidence']);
})->with(['exact', 'partial', 'negative', 'ambiguous', 'incomplete', 'not_attempted', 'conflict', 'unverified']);

it('rejects every missing required field in the independent witness', function () {
    $original = ParentResultProbe::snapshot();
    $walk = function (array $value, array $path = []) use (&$walk): array {
        $paths = [];
        foreach ($value as $key => $item) {
            $next = [...$path, $key];
            if (is_string($key) && ! in_array($key, ['es-co', 'en-ap', 'g-identity', 'h-identity', 'h-sections'], true)) {
                $paths[] = $next;
            }
            if (is_array($item)) {
                $paths = [...$paths, ...$walk($item, $next)];
            }
        }

        return $paths;
    };
    foreach ($walk($original) as $path) {
        $mutant = $original;
        $node = &$mutant;
        foreach (array_slice($path, 0, -1) as $key) {
            $node = &$node[$key];
        }
        unset($node[$path[array_key_last($path)]]);
        unset($node);
        expect(fn () => WixLookupResult::fromArray($mutant))->toThrow(LogicException::class);
    }
});

it('rejects every forbidden status resolution combination', function (string $status, string $resolution) {
    $dto = ParentResultProbe::snapshot('exact');
    $dto['status'] = $status;
    $dto['resolution'] = $resolution;
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
})->with([
    ['success', 'ambiguous'], ['success', 'incomplete'], ['success', 'not_attempted'],
    ['partial', 'no_exact_match'], ['partial', 'ambiguous'], ['partial', 'incomplete'], ['partial', 'not_attempted'],
    ['failed', 'exact'], ['failed', 'no_exact_match'],
]);

it('rejects silently coercible literals and internal contradictions', function (Closure $mutate) {
    $dto = ParentResultProbe::snapshot('partial');
    $mutate($dto);
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
})->with([
    'version float' => [function (array &$d): void {
        $d['contract_version'] = 1.0;
    }],
    'alias' => [function (array &$d): void {
        $d['evidence_registry'] = $d['evidence'];
    }],
    'product missing' => [function (array &$d): void {
        $d['product'] = null;
    }],
    'source missing' => [function (array &$d): void {
        $d['source'] = null;
    }],
    'literal changed' => [function (array &$d): void {
        $d['product']['identity']['code'] = '51515R';
    }],
    'brand guessed' => [function (array &$d): void {
        $d['product']['identity']['brand'] = 'wix';
    }],
    'seven lists never null' => [function (array &$d): void {
        $d['product']['documents'] = null;
    }],
    'identifier numeric' => [function (array &$d): void {
        $d['product']['identity']['identifiers'][0]['value'] = 1;
    }],
    'negative budget' => [function (array &$d): void {
        $d['meta']['budget_used']['nodes'] = -1;
    }],
    'request accounting' => [function (array &$d): void {
        $d['meta']['budget_used']['requests'] = 0;
    }],
    'one stage only' => [function (array &$d): void {
        $d['product']['identity']['evidence_refs'] = ['g-identity'];
    }],
    'uniqueness not exhausted' => [function (array &$d): void {
        $d['scope']['exhaustive_profiles'] = [];
    }],
    'regional merge' => [function (array &$d): void {
        $d['source']['profile'] = 'en-ap';
    }],
    'numeric raw' => [function (array &$d): void {
        $d['product']['observations'][5]['value_raw'] = 123.0;
    }],
    'numeric lexeme' => [function (array &$d): void {
        $d['product']['observations'][5]['value_raw'] = '001';
    }],
    'missing present' => [function (array &$d): void {
        $d['product']['observations'][0]['value_presence'] = 'present';
    }],
    'null empty' => [function (array &$d): void {
        $d['product']['observations'][1]['value_raw'] = '';
    }],
    'boolean changed' => [function (array &$d): void {
        $d['product']['observations'][3]['value_raw'] = 'TRUE';
    }],
    'object flattened' => [function (array &$d): void {
        $d['product']['observations'][6]['value_raw'] = '001';
    }],
    'new enum' => [function (array &$d): void {
        $d['product']['observations'][0]['raw_type'] = 'date';
    }],
    'negative order' => [function (array &$d): void {
        $d['product']['dimensions'][0]['order'] = -1;
    }],
    'no occurrence' => [function (array &$d): void {
        $d['product']['applications'][0]['occurrences'] = [];
    }],
    'position string' => [function (array &$d): void {
        $d['product']['media'] = [ParentResultProbe::snapshot()['product']['media'][0]];
        $d['product']['media'][0]['position_published'] = '1';
    }],
    'params collapsed' => [function (array &$d): void {
        $d['product']['applications'][0]['navigation']['params'] = ['id' => '01'];
    }],
    'incorrect group' => [function (array &$d): void {
        $d['coverage']['groups']['resources']['complete'] = true;
    }],
    'failed complete' => [function (array &$d): void {
        $d['coverage']['sections']['media']['complete'] = true;
    }],
]);

it('retains raw strings and repeated source fields without normalization or deduplication', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['product']['observations'][2]['value_raw'] = '000\t true 132.003 -17.777 3/4-16 mm';
    $dto['product']['observations'][] = $dto['product']['observations'][2];
    $result = WixLookupResult::fromArray($dto);
    expect($result->product->observations[2]->value_raw)->toBe('000\t true 132.003 -17.777 3/4-16 mm')->and(count($result->product->observations))->toBe(9);
});

it('preserves numeric string attempt IDs without PHP array key coercion', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['attempts'][0]['attempt_id'] = '123';
    $dto['evidence']['g-identity']['attempt_id'] = '123';
    $result = WixLookupResult::fromArray($dto);
    expect($result->attempts[0]->attempt_id)->toBe('123')->and($result->evidence['g-identity']->attempt_id)->toBe('123');
});

it('keeps an additive observation source key without changing contract version', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['product']['observations'][0]['source_key'] = 'pressurem';
    $result = WixLookupResult::fromArray($dto);
    expect($result->product->observations[0]->source_key)->toBe('pressurem')->and($result->contract_version)->toBe('1.0');
});
