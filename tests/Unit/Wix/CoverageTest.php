<?php

use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\SurfaceProgress;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Parsing\CoverageEvaluator;
use App\Support\Wix\Profiles\SurfaceInventory;
use App\Support\Wix\ResultFactory;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\ParentResultProbe;

it('derives all three AND groups over every combination of the eight sections', function (int $mask) {
    $sections = [];
    foreach (CoverageSections::NAMES as $bit => $name) {
        $complete = ($mask & (1 << $bit)) !== 0;
        $sections[$name] = ['state' => $complete ? 'captured' : 'not_checked', 'complete' => $complete, 'reasons' => $complete ? [] : ['test gap'], 'evidence_refs' => $complete ? ['test-evidence'] : []];
    }
    $coverage = (new CoverageEvaluator)->evaluate(CoverageSections::fromArray($sections));
    expect($coverage->groups->identity->complete)->toBe(($mask & 1) !== 0)
        ->and($coverage->groups->technical->complete)->toBe(($mask & 158) === 158)
        ->and($coverage->groups->resources->complete)->toBe(($mask & 96) === 96)
        ->and($coverage->complete)->toBe($mask === 255);
})->with(range(0, 255));

it('constructs the six valid outcomes without caller supplied global status or groups', function (string $scenario) {
    $expected = ParentResultProbe::snapshot($scenario);
    $seed = WixLookupResult::fromArray($expected);
    $result = (new ResultFactory)->build($seed->requested_code, $seed->resolution, $seed->product, $seed->scope, $seed->source, $seed->attempts, $seed->evidence, $seed->coverage->sections, $seed->diagnostics, $seed->meta);
    expect($result->toArray())->toEqual($expected);
    ContractWitness::assert($result->toArray());
})->with(['exact', 'partial', 'negative', 'ambiguous', 'incomplete', 'not_attempted']);

it('does not pretend captured material alone demonstrates closure', function () {
    $seed = ParentResultProbe::snapshot('partial');
    expect($seed['coverage']['complete'])->toBeFalse();
    $seed['coverage']['complete'] = true;
    expect(fn () => WixLookupResult::fromArray($seed))->toThrow(LogicException::class);
});

it('requires every fixed joint surface and its page closure for all sixteen inventory cells', function (string $profile, string $section) {
    $inventory = new SurfaceInventory($profile);
    $required = $inventory->surfaces($section);
    $progress = array_map(fn ($surface) => new SurfaceProgress($surface, CoverageState::PublishedEmpty, true, true, true, false, ['proof-'.$surface->value], []), $required);
    $evaluator = new CoverageEvaluator;
    expect($evaluator->section($inventory, $section, $progress)->complete)->toBeTrue();
    array_pop($progress);
    expect($evaluator->section($inventory, $section, $progress)->complete)->toBeFalse();
})->with(['es-co', 'en-ap'])->with(CoverageSections::NAMES);

it('keeps captured false for pending pagination and mandatory surface errors', function (bool $exhausted, bool $error) {
    $inventory = new SurfaceInventory('es-co');
    $progress = [new SurfaceProgress(EvidenceSurface::Html, CoverageState::Captured, true, true, $exhausted, $error, ['proof'], ['pending or required failure'])];
    $section = (new CoverageEvaluator)->section($inventory, 'oe_references', $progress);
    expect($section->state->value)->toBe('captured')->and($section->complete)->toBeFalse()->and($section->reasons)->not->toBeEmpty();
})->with([[false, false], [true, true], [false, true]]);

it('does not certify populated document support from a claimed successful capture', function () {
    $inventory = new SurfaceInventory('en-ap');
    $progress = [new SurfaceProgress(EvidenceSurface::Html, CoverageState::Captured, true, true, true, false, ['proof'], [])];
    $section = (new CoverageEvaluator)->section($inventory, 'documents', $progress);
    expect($section->state->value)->toBe('unsupported')->and($section->complete)->toBeFalse();
});
