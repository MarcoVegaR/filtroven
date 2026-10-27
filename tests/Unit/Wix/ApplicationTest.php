<?php

use App\Support\Wix\Parsing\ApplicationExtractor;
use App\Support\Wix\Parsing\ValueReader;
use Tests\Support\Wix\ProductionHarness;

it('captures authentic logical applications and proves each responsive occurrence', function (string $fixture, int $count) {
    $html = ProductionHarness::html($fixture);
    $items = (new ApplicationExtractor(new ValueReader))->extract($html, str_starts_with($fixture, 'es-co') ? 'No hay información disponible' : 'No Data Available');
    expect($items)->toHaveCount($count);
    foreach ($items as $item) {
        expect($item->occurrences)->toHaveCount(2)->and($item->period_raw)->toBeNull()->and($item->navigation->params)->not->toBeEmpty();
        expect(array_map(fn ($o): string => $o->surface, $item->occurrences))->toBe(['desktop', 'tablet']);
    }
    if ($fixture === 'es-co-42831-detail') {
        expect($items[0]->engine_raw)->toBe('')->and($items[0]->manufacturer_raw)->toBe('CHEVROLET');
        expect(array_any($items[1]->cells, fn ($cell): bool => $cell->value_raw === '9999-12-31'))->toBeTrue();
    }
})->with([['es-co-42831-detail', 11], ['en-ap-51515-detail', 94]]);

it('does not collapse repeated parameters or different application identities', function () {
    $extractor = new ApplicationExtractor(new ValueReader);
    $params = $extractor->parameters(rawurlencode('?id=001&id=002&engine=&name=A+B'));
    expect(array_map(fn ($p): array => [$p->name, $p->value], $params))->toBe([['id', '001'], ['id', '002'], ['engine', ''], ['name', 'A B']]);
    $raw = ProductionHarness::corpus()->body('es-co-42831-detail');
    $position = strpos($raw, 'class="cmp-table__tablet"');
    $derived = substr($raw, 0, $position).preg_replace('/vehicleTypeId%3D00000000396662/', 'vehicleTypeId%3D00000000396663', substr($raw, $position), 1);
    $items = $extractor->extract(ProductionHarness::html('es-co-42831-detail', $derived), 'No hay información disponible');
    expect($items)->toHaveCount(12)->and($items[0]->occurrences)->toHaveCount(1);
});

it('recognizes only the scoped authentic empty application template', function (string $fixture, string $marker) {
    expect((new ApplicationExtractor(new ValueReader))->extract(ProductionHarness::html($fixture), $marker))->toBe([]);
})->with([['es-co-51515-detail', 'No hay información disponible'], ['en-ap-51515R-detail', 'No Data Available']]);
