<?php

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\OperationalFailure;
use Tests\Support\Wix\Prototype\ParentResultProbe;

function wixEvidenceResponse(?string $final = null): CapturedResponse
{
    return new CapturedResponse(new RequestPlan('123', 'es-co', EvidenceSurface::Html, 'https://www.wixfilters.com/es-co/catalog/results/product.html/51515.html', []), 200, [], 'inert', $final, '2026-10-24T12:00:00.123Z');
}

it('creates unique nonnumeric IDs and preserves literal excerpts and capture times', function () {
    $builder = new EvidenceBuilder(4096);
    $a = $builder->capture(wixEvidenceResponse(), '#product/h1', '001');
    $b = $builder->capture(wixEvidenceResponse(), '#product/h1', '001');
    expect([$a, $b])->toBe(['e-1', 'e-2'])->and(array_keys($builder->all()))->toBe(['e-1', 'e-2'])->and($builder->all()[$a]->raw_excerpt)->toBe('001')->and($builder->all()[$a]->captured_at)->toBe('2026-10-24T12:00:00.123Z')->and($builder->all()[$a]->attempt_id)->toBe('123');
});

it('records only a genuinely changed final URL', function () {
    $builder = new EvidenceBuilder(4096);
    $id = $builder->capture(wixEvidenceResponse('https://www.wixfilters.com/es-co/catalog/results/product.html/51515-wix.html'), '#product/h1', '51515');
    expect($builder->all()[$id]->final_url)->toBe('https://www.wixfilters.com/es-co/catalog/results/product.html/51515-wix.html');
    expect(fn () => wixEvidenceResponse('https://www.wixfilters.com/es-co/catalog/results/product.html/51515.html'))->toThrow(LogicException::class);
});

it('rejects hash-only or unlocated evidence rather than inventing proof', function (string $locator, string $excerpt) {
    expect(fn () => (new EvidenceBuilder(4096))->capture(wixEvidenceResponse(), $locator, $excerpt))->toThrow(LogicException::class);
})->with([['', '51515'], ['external-log', '51515'], ['#product', str_repeat('a', 64)]]);

it('rejects oversized proof without truncation or partial append', function () {
    $builder = new EvidenceBuilder(8);
    expect(fn () => $builder->capture(wixEvidenceResponse(), '#product', '132.003 mm'))->toThrow(OperationalFailure::class, 'budget_exhausted');
    expect($builder->all())->toBe([]);
});

it('rejects provenance corruption across every result reference domain', function (Closure $mutate) {
    $dto = ParentResultProbe::snapshot('partial');
    $mutate($dto);
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
})->with([
    'orphan product' => [function (array &$d): void {
        $d['product']['dimensions'][0]['evidence_refs'] = ['absent'];
    }],
    'orphan source' => [function (array &$d): void {
        $d['source']['evidence_refs'] = ['absent'];
    }],
    'orphan attempt' => [function (array &$d): void {
        $d['attempts'][0]['evidence_refs'][] = 'absent';
    }],
    'orphan coverage' => [function (array &$d): void {
        $d['coverage']['sections']['media']['evidence_refs'] = ['absent'];
    }],
    'orphan diagnostic' => [function (array &$d): void {
        $d['diagnostics'][0]['evidence_refs'] = ['absent'];
    }],
    'wrong profile' => [function (array &$d): void {
        $d['evidence']['g-identity']['profile'] = 'en-ap';
    }],
    'wrong attempt' => [function (array &$d): void {
        $d['evidence']['g-identity']['attempt_id'] = 'absent';
    }],
    'non UTC' => [function (array &$d): void {
        $d['evidence']['g-identity']['captured_at'] = '2026-10-24T12:00:00+00:00';
    }],
    'selected source mismatch' => [function (array &$d): void {
        $d['source']['url'] .= '-wrong';
    }],
    'capture outside attempt' => [function (array &$d): void {
        $d['evidence']['g-identity']['captured_at'] = '2099-01-01T00:00:00Z';
    }],
    'attempt outside invocation' => [function (array &$d): void {
        $d['meta']['requested_at'] = '2099-01-01T00:00:00Z';
        $d['meta']['returned_at'] = '2099-01-01T00:00:00Z';
    }],
]);

it('does not invent provider evidence when an attempt never received a response', function () {
    $dto = ParentResultProbe::snapshot('incomplete');
    $dto['evidence'] = [];
    $dto['attempts'][0]['evidence_refs'] = [];
    $dto['diagnostics'][0]['evidence_refs'] = [];
    $dto['diagnostics'][0]['code'] = 'transport_error';
    $result = WixLookupResult::fromArray($dto);
    expect($result->product)->toBeNull()->and($result->source)->toBeNull()->and($result->evidence)->toBe([]);
});
