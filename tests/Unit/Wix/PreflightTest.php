<?php

use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\LookupLimits;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Preflight;
use App\Support\Wix\Profiles\SurfaceInventory;
use App\Support\Wix\Runtime\RuntimeCapabilities;

it('keeps unapproved auxiliary limits unset despite the enabled switch', function () {
    $config = require __DIR__.'/../../../config/wix.php';
    expect($config['enabled'])->toBeTrue()->and($config['limits']['input_max_bytes'])->toBeNull();
    expect(fn () => (new Preflight)->validate('51515', false, $config['limits']))->toThrow(OperationalFailure::class, 'disabled');
    expect(fn () => (new Preflight)->validate('51515', true, $config['limits']))->toThrow(OperationalFailure::class, 'invalid_configuration');
});

it('rejects invalid literal input before disablement without rewriting it', function (string $literal) {
    expect(fn () => (new Preflight)->validate($literal, false, []))->toThrow(OperationalFailure::class, 'invalid_input');
})->with(['empty' => [''], 'leading' => [' 51515'], 'trailing' => ['51515 '], 'control' => ["515\n15"], 'nul' => ["515\0"]]);

it('does not invent a commercial code grammar or normalize literal strings', function (string $literal) {
    $config = require __DIR__.'/../../../config/wix.php';
    $limits = array_replace($config['limits'], ['input_max_bytes' => 128, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000]);
    expect((new Preflight)->validate($literal, true, $limits))->toBeInstanceOf(LookupLimits::class);
})->with(['00123', 'wix51515', '51515R', 'A/B', 'ÉQ 1', '0']);

it('rejects missing infinite coerced or incoherent configuration before any work', function (string $key, mixed $value) {
    $config = require __DIR__.'/../../../config/wix.php';
    $limits = array_replace($config['limits'], ['input_max_bytes' => 128, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000], [$key => $value]);
    expect(fn () => (new Preflight)->validate('51515', true, $limits))->toThrow(OperationalFailure::class, 'invalid_configuration');
})->with([['max_requests', 0], ['total_timeout_ms', INF], ['max_nodes_total', '250000'], ['max_search_pages', null], ['max_retries', -1], ['connect_timeout_ms', 9000], ['outbound_concurrency_limit', 2], ['finalization_reserve_ms', 20000]]);

it('constructs independent parent ledgers for identical invocations without I/O', function () {
    $config = require __DIR__.'/../../../config/wix.php';
    $limits = LookupLimits::fromArray(array_replace($config['limits'], ['input_max_bytes' => 128, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000]));
    $a = new LookupContext('001', new LookupBudget($limits), '2026-10-24T12:00:00Z', new EvidenceBuilder(4096));
    $b = new LookupContext('001', new LookupBudget($limits), '2026-10-24T12:00:01Z', new EvidenceBuilder(4096));
    $a->budget->begin();
    $a->budget->finish();
    expect($a->requestedCode)->toBe('001')->and($a->budget->snapshot()->requests)->toBe(1)->and($b->budget->snapshot()->requests)->toBe(0)->and($a->evidence === $b->evidence)->toBeFalse();
});

it('fails closed for unsupported actual capability snapshots before any child DNS or HTTP', function (string $key, mixed $value) {
    $runtime = new RuntimeCapabilities;
    $observed = $runtime->collect();
    $observed[$key] = $value;
    expect(fn () => $runtime->assertAvailable($observed))->toThrow(OperationalFailure::class, 'invalid_configuration');
})->with([['sapi', 'fpm-fcgi'], ['os', 'Windows'], ['php', 80399], ['curl', '8.99.0'], ['functions', false], ['document', false], ['memory_max', 0], ['pids_max', 0], ['php', '80500']]);

it('retains exactly eight joint inventories per profile without certifying populated documents', function () {
    foreach (['es-co', 'en-ap'] as $profile) {
        $inventory = new SurfaceInventory($profile);
        foreach (CoverageSections::NAMES as $section) {
            expect($inventory->surfaces($section))->not->toBeEmpty();
        }
        expect($inventory->supportsPopulatedDocuments())->toBeFalse()->and($inventory->documentGap())->toBe('AUTHENTIC_POSITIVE_DOCUMENT_MISSING');
    }
    expect(fn () => new SurfaceInventory('en-us'))->toThrow(LogicException::class);
});
