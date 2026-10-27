<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;

it('preserves safe transport causes through search detail and partial resource failure mappings', function (string $surface) {
    $corpus = ProductionHarness::corpus();
    $stage = $surface === 'search' ? 'dns.connect_deadline' : 'receive.pin_expired';
    $failure = static function () use ($surface, $stage): never {
        throw new OperationalFailure($surface === 'search' ? DiagnosticCode::BudgetExhausted : DiagnosticCode::TransportError, $stage);
    };
    $search = array_map(fn (int $page): string => $corpus->body('es-co-51515-search-p'.$page.'-s1'), [1, 2, 3]);
    $responses = match ($surface) {
        'search' => [$failure],
        'detail' => [...$search, $failure],
        'assets' => [...$search, $corpus->body('es-co-51515-detail'), $failure],
    };
    $transport = new ProductionHarness($responses);
    $config = ProductionHarness::configuration(['page_size' => 1]);
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect(array_column($dto['diagnostics'], 'stage'))->toContain($stage)
        ->and($dto['status'])->toBe($surface === 'assets' ? 'partial' : 'failed')
        ->and($dto['resolution'])->toBe($surface === 'assets' ? 'exact' : 'incomplete');
    if ($surface === 'assets') {
        expect($dto['coverage']['groups']['technical']['complete'])->toBeTrue()
            ->and($dto['product']['oe_references'])->toHaveCount(25)->and($dto['product']['media'])->toHaveCount(2);
    }
})->with(['search', 'detail', 'assets']);

it('continues independent sections after a derived malformed gallery', function () {
    $corpus = ProductionHarness::corpus();
    $html = str_replace('data-gallery-items="[{', 'data-gallery-items="BROKEN[{', $corpus->body('en-ap-51515R-detail'));
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $html, $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['status'])->toBe('partial')->and($result['coverage']['groups']['technical']['complete'])->toBeTrue()
        ->and($result['coverage']['sections']['documents']['complete'])->toBeTrue()->and($result['coverage']['sections']['media']['complete'])->toBeFalse()
        ->and($result['product']['media'])->toHaveCount(4);
});

it('invalidates the entire product after a late comparable REACH contradiction', function () {
    $corpus = ProductionHarness::corpus();
    $raw = $corpus->body('en-ap-51515R-detail');
    $offset = strpos($raw, 'cmp-product__reach-information-product');
    $derived = substr($raw, 0, $offset).preg_replace('/<span>51515R<\/span>/', '<span>51515</span>', substr($raw, $offset), 1);
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $derived]);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['resolution'])->toBe('incomplete')->and($result['product'])->toBeNull()->and($result['source'])->toBeNull()
        ->and($result['coverage']['sections']['identity']['complete'])->toBeFalse()->and(count($transport->requests))->toBe(3);
});

it('never turns derived populated pending or missing sections into complete empty coverage', function (string $kind) {
    $corpus = ProductionHarness::corpus();
    $raw = $corpus->body('en-ap-51515R-detail');
    $offset = strpos($raw, 'id="downloads"');
    $tail = substr($raw, $offset);
    $tail = match ($kind) {
        'populated' => preg_replace('/No Data Available/', '<a href="https://example.invalid/synthetic-future.pdf">Synthetic</a>', $tail, 1),
        'pending' => preg_replace('/No Data Available/', '<div data-fetch="later">Loading…</div>', $tail, 1),
        'missing' => preg_replace('/id="downloads"/', 'id="unknown-downloads"', $tail, 1),
        'blank' => preg_replace('/No Data Available/', '', $tail, 1),
    };
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), substr($raw, 0, $offset).$tail, $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['status'])->toBe('partial')->and($result['coverage']['sections']['documents']['complete'])->toBeFalse()
        ->and($result['coverage']['sections']['documents']['state'])->toBe('unsupported')->and($result['coverage']['sections']['media']['complete'])->toBeTrue();
    expect(array_any($result['diagnostics'], fn ($d): bool => $d['section'] === 'documents' && $d['code'] === 'unsupported_section' && $d['severity'] === 'error' && ! $d['recovered']))->toBeTrue();
    expect(count($transport->requests))->toBe(4);
})->with(['populated', 'pending', 'missing', 'blank']);

it('keeps the whole global request budget and identity when asset admission exhausts', function () {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-detail'), $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration(['max_requests' => 3]);
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['status'])->toBe('partial')->and($result['meta']['budget_used']['requests'])->toBe(3)->and($result['product']['media'])->toHaveCount(8)
        ->and($result['coverage']['sections']['media']['complete'])->toBeFalse()->and($result['diagnostics'][0]['code'])->toBe('budget_exhausted');
});

it('keeps valid gallery siblings and independent sections after a derived invalid resource', function (mixed $invalid) {
    $corpus = ProductionHarness::corpus();
    $html = preg_replace_callback('/(data-(?:g-binding-)?gallery-items=")([^"]*)(")/', function (array $match) use ($invalid): string {
        $items = json_decode(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 32, JSON_THROW_ON_ERROR);
        $items[1] = $invalid;

        return $match[1].htmlspecialchars(json_encode($items, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8').$match[3];
    }, $corpus->body('en-ap-51515R-detail'));
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $html, $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('partial')->and($dto['product']['media'])->toHaveCount(11)
        ->and($dto['coverage']['sections']['media']['state'])->toBe('captured')->and($dto['coverage']['sections']['media']['complete'])->toBeFalse()
        ->and($dto['coverage']['groups']['technical']['complete'])->toBeTrue()->and(count($transport->requests))->toBe(4);
    expect(array_any($dto['diagnostics'], fn (array $d): bool => $d['section'] === 'media' && $d['code'] === 'schema_error' && $d['severity'] === 'error' && ! $d['recovered']))->toBeTrue();
})->with(['scalar resource' => [42], 'null resource' => [null], 'invalid required reference' => [['path' => null]]]);

it('keeps valid GraphQL attribute siblings when an individual source field cannot fit the lossless representation', function () {
    $corpus = ProductionHarness::corpus();
    $search = str_replace('"key":"height","value":"131.318",', '"key":"height","value":"'.str_repeat('x', 8300).'",', $corpus->body('en-ap-51515R-search-p1-s15'));
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $search, $corpus->body('en-ap-51515R-detail'), $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('partial')->and($dto['coverage']['sections']['dimensions']['complete'])->toBeFalse();
    $keys = array_column($dto['product']['dimensions'], 'source_key');
    expect($keys)->toContain('thread_size')->not->toContain('height');
    expect($dto['coverage']['sections']['specifications']['complete'])->toBeTrue()->and($dto['coverage']['sections']['media']['complete'])->toBeTrue();
    expect(array_any($dto['diagnostics'], fn (array $d): bool => $d['section'] === 'dimensions' && $d['code'] === 'unsupported_section' && ! $d['recovered']))->toBeTrue();
});

it('keeps valid metadata siblings without certifying a page containing an invalid resource', function (mixed $invalid) {
    $corpus = ProductionHarness::corpus();
    $assets = json_decode($corpus->body('en-ap-51515R_WIX-assets-l25-o0'), true, 32, JSON_THROW_ON_ERROR);
    $assets['hits'][1] = $invalid;
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-detail'), json_encode($assets, JSON_THROW_ON_ERROR)]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('partial')->and($dto['product']['media'])->toHaveCount(11)
        ->and($dto['coverage']['sections']['media']['state'])->toBe('captured')->and($dto['coverage']['sections']['media']['complete'])->toBeFalse()
        ->and($dto['coverage']['groups']['technical']['complete'])->toBeTrue()->and(count($transport->requests))->toBe(4);
    expect(array_any($dto['diagnostics'], fn (array $d): bool => $d['section'] === 'media' && $d['code'] === 'schema_error' && ! $d['recovered']))->toBeTrue();
})->with(['scalar hit' => [42], 'null hit' => [null], 'invalid required metadata' => [['link' => 'synthetic', 'metaData' => null]]]);

it('retains independently readable HTML records while reporting a local structural field gap', function (string $section) {
    $corpus = ProductionHarness::corpus();
    $html = $corpus->body('es-co-42831-detail');
    if ($section === 'dimensions') {
        $offset = strpos($html, 'id="dimensions"');
        $html = substr($html, 0, $offset).preg_replace('/<tbody[^>]*>/', '$0<tr><th>SYNTHETIC unsupported row</th></tr>', substr($html, $offset), 1);
    } elseif ($section === 'applications') {
        $offset = strpos($html, 'id="applications"');
        $html = substr($html, 0, $offset).preg_replace('/data-g-binding-path="[^"]*"/', 'data-g-binding-path="/synthetic-unsupported"', substr($html, $offset), 1);
    } else {
        $html = str_replace('class="cmp-accordion__title">GENERAL MOTORS (GM)', 'class="unrecognized-title">GENERAL MOTORS (GM)', $html);
    }
    $transport = new ProductionHarness([$corpus->body('es-co-42831-search-p1-s15'), $html, static function (): never {
        throw new OperationalFailure(DiagnosticCode::TransportError, 'metadata-corpus-gap');
    }]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('42831')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('partial')->and($dto['coverage']['sections'][$section]['state'])->toBe('captured')
        ->and($dto['coverage']['sections'][$section]['complete'])->toBeFalse()->and($dto['coverage']['sections']['specifications']['complete'])->toBeTrue();
    expect(array_any($dto['diagnostics'], fn (array $d): bool => $d['section'] === $section && $d['code'] === 'unsupported_section' && ! $d['recovered']))->toBeTrue();
    if ($section === 'dimensions') {
        expect(array_filter($dto['product']['dimensions'], fn (array $d): bool => $d['section'] === 'dimensions'))->toHaveCount(3);
    } elseif ($section === 'applications') {
        expect($dto['product']['applications'])->toHaveCount(11)
            ->and(array_sum(array_map(fn (array $a): int => count($a['occurrences']), $dto['product']['applications'])))->toBe(21);
    } else {
        expect($dto['product']['oe_references'])->toHaveCount(6)->and($dto['product']['oe_references'][4]['manufacturer_raw'])->toBeNull()
            ->and($dto['product']['oe_references'][4]['reference_raw'])->toBe('96536696')->and($dto['product']['oe_references'][5]['manufacturer_raw'])->not->toBeNull();
    }
})->with(['dimensions', 'applications', 'oe_references']);

it('preserves a completed metadata hit through public fetch when the default evidence budget exhausts on a later hit', function () {
    $corpus = ProductionHarness::corpus();
    $assets = json_decode($corpus->body('en-ap-51515R_WIX-assets-l25-o0'), true, 32, JSON_THROW_ON_ERROR);
    foreach ([0, 1] as $index) {
        foreach (range(1, 2200) as $field) {
            $assets['hits'][$index]['synthetic_extra_'.$field] = 'literal';
        }
    }
    $transport = new ProductionHarness([
        $corpus->body('es-co-51515R-search-p1-s15'),
        $corpus->body('en-ap-51515R-search-p1-s15'),
        $corpus->body('en-ap-51515R-detail'),
        json_encode($assets, JSON_THROW_ON_ERROR),
    ]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('partial')->and($dto['resolution'])->toBe('exact');
    expect(array_column($dto['diagnostics'], 'code'))->toContain('budget_exhausted');
    expect(array_any($dto['evidence'], fn (array $item): bool => $item['surface'] === 'asset_metadata' && $item['locator'] === '/hits/0/synthetic_extra_2200'))->toBeTrue();
    expect($dto['product']['media'])->toHaveCount(9);
    expect($dto['product']['media'][8]['reference_raw'])->toBe($assets['hits'][0]['link'])
        ->and($dto['product']['media'][8]['order'])->toBe(8)->and($transport->requests)->toHaveCount(4)
        ->and($dto['coverage']['sections']['media']['state'])->toBe('captured')
        ->and($dto['coverage']['sections']['media']['complete'])->toBeFalse()
        ->and($dto['attempts'][3]['pages'])->toBe([1])->and($dto['attempts'][3]['outcome'])->toBe('incomplete');
    expect(array_any($dto['diagnostics'], fn (array $item): bool => $item['section'] === 'media' && $item['code'] === 'budget_exhausted' && $item['severity'] === 'error' && ! $item['recovered']))->toBeTrue();
});
