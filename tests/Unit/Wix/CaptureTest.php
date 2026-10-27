<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Parsing\TechnicalSectionExtractor;
use App\Support\Wix\Parsing\ValueReader;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;

it('preserves missing null empty literal types and number lexemes through the production child', function () {
    $context = ProductionHarness::context();
    $request = (new ProfileRegistry)->get('es-co')->search('51515', 1, 15, 's');
    $raw = '{"empty":"","null":null,"boolean":true,"string":"true","number":123456789012345678901.0012300e-17,"array":[1.00,null],"object":{"x":1.20},"unicode":"válvula θ"}';
    $response = new CapturedResponse($request, 200, [], $raw, null, gmdate('Y-m-d\TH:i:s\Z'));
    $tokens = (new SupervisedDocumentReader(new SupervisedPhpTask))->lexemes($response, $context);
    $reader = new ValueReader;
    $expected = [
        'absent' => [null, 'missing', 'missing'], 'null' => [null, 'present', 'null'], 'empty' => ['', 'present', 'string'],
        'boolean' => ['true', 'present', 'boolean'], 'string' => ['true', 'present', 'string'],
        'number' => ['123456789012345678901.0012300e-17', 'present', 'number'], 'array' => ['[1.00,null]', 'present', 'array'],
        'object' => ['{"x":1.20}', 'present', 'object'], 'unicode' => ['válvula θ', 'present', 'string'],
    ];
    foreach ($expected as $name => [$raw, $presence, $type]) {
        $field = $reader->field($name, $tokens['/'.$name] ?? null, ['e-1']);
        expect([$field->value_raw, $field->value_presence->value, $field->raw_type->value])->toBe([$raw, $presence, $type]);
    }
    expect($context->budget->snapshot()->nodes)->toBeGreaterThan(10);
});

it('captures authentic technical table literals and explicit label units', function (string $fixture, int $dimensions, int $specifications) {
    $html = ProductionHarness::html($fixture);
    $extractor = new TechnicalSectionExtractor(new ValueReader);
    $marker = str_starts_with($fixture, 'es-co') ? 'No hay información disponible' : 'No Data Available';
    $dims = $extractor->table($html, 'dimensions', $marker);
    $specs = $extractor->table($html, 'technicalData', $marker);
    expect($dims)->toHaveCount($dimensions)->and($specs)->toHaveCount($specifications);
    expect($dims[0]->unit_raw)->toBe('mm')->and($dims[2]->value_raw)->toContain('mm');
    foreach ([...$dims, ...$specs] as $observation) {
        expect($observation->raw_type->value)->toBe('string')->and($observation->evidence_refs)->not->toBeEmpty();
    }
    if ($fixture === 'es-co-51515-detail') {
        expect($dims[0]->value_raw)->toBe('132 mm')->and($dims[2]->value_raw)->toBe('3/4-16 mm')->and($specs[0]->value_raw)->toBe('26.497')->and($specs[0]->unit_raw)->toBe('l/min');
    }
    if ($fixture === 'es-co-42831-detail') {
        expect($specs[1]->value_raw)->toBe('-17.777')->and($specs[2]->value_raw)->toBe('-17.777');
    }
})->with([['es-co-51515-detail', 3, 8], ['es-co-42831-detail', 3, 3], ['en-ap-51515R-detail', 3, 6], ['en-ap-51515-detail', 3, 9]]);

it('rejects duplicate JSON members and oversized field proof instead of rounding or truncating', function () {
    $context = ProductionHarness::context();
    $request = (new ProfileRegistry)->get('es-co')->search('51515', 1, 15, 's');
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    expect(fn () => $reader->lexemes(new CapturedResponse($request, 200, [], '{"x":1,"x":2}', null, gmdate('Y-m-d\TH:i:s\Z')), $context))->toThrow(OperationalFailure::class);
    expect(fn () => (new ValueReader)->field('future', ['raw_type' => 'object', 'lexeme' => null], ['e-1']))->toThrow(OperationalFailure::class);
});

it('preserves repeated labels raw cells and identifiable extra technical paragraphs', function () {
    $raw = ProductionHarness::corpus()->body('es-co-51515-detail');
    $derived = str_replace('<td class="aem-GridColumn aem-GridColumn--default--6">132 mm</td>', '<td class="aem-GridColumn aem-GridColumn--default--6">132 mm</td><td class="aem-GridColumn aem-GridColumn--default--6">132.003 mm</td>', $raw);
    $items = (new TechnicalSectionExtractor(new ValueReader))->table(ProductionHarness::html('es-co-51515-detail', $derived), 'dimensions', 'No hay información disponible');
    expect($items)->toHaveCount(4)->and($items[0]->label_raw)->toBe($items[1]->label_raw)->and($items[0]->value_raw)->toBe('132 mm')->and($items[1]->value_raw)->toBe('132.003 mm')->and($items[1]->order)->toBe(1);
});

it('keeps typed attribute values lossless in a full derived fetch instead of coercing source numbers', function (?string $lexeme, ?string $raw, string $presence, string $type) {
    $corpus = ProductionHarness::corpus();
    $search = $corpus->body('en-ap-51515R-search-p1-s15');
    $search = str_replace('"key":"height","value":"131.318",', '"key":"height",'.($lexeme === null ? '' : '"value":'.$lexeme.','), $search);
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $search, $corpus->body('en-ap-51515R-detail'), $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    $items = array_values(array_filter($result['product']['dimensions'], fn ($o): bool => ($o['source_key'] ?? null) === 'height'));
    expect($items)->toHaveCount(1)->and([$items[0]['value_raw'], $items[0]['value_presence'], $items[0]['raw_type']])->toBe([$raw, $presence, $type]);
})->with([
    [null, null, 'missing', 'missing'], ['null', null, 'present', 'null'], ['""', '', 'present', 'string'],
    ['true', 'true', 'present', 'boolean'], ['"true"', 'true', 'present', 'string'],
    ['9007199254740993.0012300e-14', '9007199254740993.0012300e-14', 'present', 'number'],
    ['{"published":1.00}', '{"published":1.00}', 'present', 'object'], ['[1.00,null]', '[1.00,null]', 'present', 'array'],
]);

it('preserves typed qualifiers and non-string admin values without converting them into strings', function () {
    $corpus = ProductionHarness::corpus();
    $search = str_replace('"key":"height","value":"131.318","adminValue":"131.318"', '"key":"height","value":"131.318","unit":"mm","qualifier":{"max":false,"precision":1.00},"adminValue":true', $corpus->body('en-ap-51515R-search-p1-s15'));
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $search, $corpus->body('en-ap-51515R-detail'), $corpus->body('en-ap-51515R_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    $items = array_values(array_filter($result['product']['dimensions'], fn ($o): bool => str_starts_with($o['source_key'] ?? '', 'height')));
    expect($items[0]['unit_raw'])->toBe('mm')->and($items[0]['raw_admin_value'])->toBeNull()
        ->and($items[1]['source_key'])->toBe('height.adminValue')->and($items[1]['raw_type'])->toBe('boolean')
        ->and($items[2]['value_raw'])->toBe('{"max":false,"precision":1.00}')->and($items[2]['raw_type'])->toBe('object');
});

it('bounds cumulative evidence without truncating or losing previously committed proof', function () {
    $request = (new ProfileRegistry)->get('es-co')->search('51515', 1, 15, 's');
    $response = new CapturedResponse($request, 200, [], '{}', null, gmdate('Y-m-d\TH:i:s\Z'));
    $builder = new EvidenceBuilder(100, 1, 10000);
    $first = $builder->capture($response, '/attribute', 'literal first value');
    expect(fn () => $builder->capture($response, '/other', 'second'))->toThrow(OperationalFailure::class, 'budget_exhausted');
    expect($builder->all())->toHaveCount(1)->and($builder->all()[$first]->raw_excerpt)->toBe('literal first value');
});
