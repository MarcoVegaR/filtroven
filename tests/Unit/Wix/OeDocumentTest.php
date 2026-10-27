<?php

use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\DocumentExtractor;
use App\Support\Wix\Parsing\OeReferenceExtractor;
use Tests\Support\Wix\ProductionHarness;

it('preserves authentic OE manufacturers references repeats and publication order', function (string $fixture, int $count, int $manufacturers) {
    $items = (new OeReferenceExtractor)->extract(ProductionHarness::html($fixture), str_starts_with($fixture, 'es-co') ? 'No hay información disponible' : 'No Data Available');
    expect($items)->toHaveCount($count)->and(array_unique(array_map(fn ($i): string => $i->manufacturer_raw, $items)))->toHaveCount($manufacturers);
    expect(array_map(fn ($i): int => $i->order, $items))->toBe(range(0, $count - 1));
})->with([['es-co-51515-detail', 25, 1], ['en-ap-51515-detail', 28, 4]]);

it('inspects authentic empty document containers without certifying populated support', function (string $fixture) {
    $marker = str_starts_with($fixture, 'es-co') ? 'No hay información disponible' : 'No Data Available';
    $html = ProductionHarness::html($fixture);
    expect((new DocumentExtractor)->extract($html, $marker))->toBe([])->and($html->context->evidence->all())->not->toBeEmpty();
})->with(['es-co-51515-detail', 'es-co-42831-detail', 'en-ap-51515R-detail', 'en-ap-51515-detail']);

it('keeps populated and unrecognized documents unsupported without fetching links', function (string $replacement) {
    $raw = ProductionHarness::corpus()->body('en-ap-51515R-detail');
    $offset = strpos($raw, 'id="downloads"');
    $derived = substr($raw, 0, $offset).preg_replace('/No Data Available/', $replacement, substr($raw, $offset), 1);
    $html = ProductionHarness::html('en-ap-51515R-detail', $derived);
    expect(fn () => (new DocumentExtractor)->extract($html, 'No Data Available'))->toThrow(OperationalFailure::class, 'unsupported_section');
})->with(['<a href="https://example.invalid/synthetic.pdf">synthetic future document</a>', '', 'Loading…']);

it('does not reuse the previous OE manufacturer for a derived unrecognized group', function () {
    $raw = ProductionHarness::corpus()->body('es-co-42831-detail');
    $derived = str_replace('GENERAL MOTORS (GM)', '', $raw);
    $items = (new OeReferenceExtractor)->extract(ProductionHarness::html('es-co-42831-detail', $derived), 'No hay información disponible');
    expect($items[4]->manufacturer_raw)->toBe('')->and($items[4]->reference_raw)->toBe('96536696');
    $derived = str_replace('class="cmp-accordion__title">GENERAL MOTORS (GM)', 'class="unrecognized-title">GENERAL MOTORS (GM)', $raw);
    expect(fn () => (new OeReferenceExtractor)->extract(ProductionHarness::html('es-co-42831-detail', $derived), 'No hay información disponible'))->toThrow(OperationalFailure::class, 'unsupported_section');
});
