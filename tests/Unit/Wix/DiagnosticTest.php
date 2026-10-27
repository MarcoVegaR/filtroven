<?php

use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\ResultFactory;
use Tests\Support\Wix\Prototype\ParentResultProbe;

it('keeps operational failures typed and leaves programming exceptions visible', function () {
    $error = new OperationalFailure(DiagnosticCode::TransportError, 'search', 'attempt-1');
    expect($error->diagnostic()->code)->toBe(DiagnosticCode::TransportError)->and($error->diagnostic()->recovered)->toBeFalse();
    $seed = WixLookupResult::fromArray(ParentResultProbe::snapshot('partial'));
    expect(fn () => (new ResultFactory)->build($seed->requested_code, $seed->resolution, $seed->product, $seed->scope, $seed->source, $seed->attempts, $seed->evidence, $seed->coverage->sections, [], $seed->meta))->toThrow(LogicException::class);
});

it('rejects diagnostic contradictions without broad exception translation', function (Closure $mutate) {
    $dto = ParentResultProbe::snapshot('partial');
    $mutate($dto);
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
})->with([
    'missing terminal' => [function (array &$d): void {
        $d['diagnostics'] = [];
    }],
    'invented code' => [function (array &$d): void {
        $d['diagnostics'][0]['code'] = 'dns_failure';
    }],
    'recovered error' => [function (array &$d): void {
        $d['diagnostics'][0]['recovered'] = true;
    }],
    'warning does not close gap' => [function (array &$d): void {
        $d['diagnostics'][0]['severity'] = 'warning';
    }],
    'unknown section' => [function (array &$d): void {
        $d['diagnostics'][0]['section'] = 'invented';
    }],
]);

it('allows recovered warnings and accessory warnings without downgrading proven complete coverage', function (bool $recovered) {
    $dto = ParentResultProbe::snapshot();
    $dto['diagnostics'][] = ['code' => 'transport_error', 'stage' => 'accessory', 'attempt_id' => null, 'severity' => 'warning', 'recovered' => $recovered, 'message' => 'SYNTHETIC optional or recovered event', 'section' => null, 'evidence_refs' => []];
    $result = WixLookupResult::fromArray($dto);
    expect($result->status->value)->toBe('success')->and($result->coverage->complete)->toBeTrue();
})->with([true, false]);

it('invalidates product and source on a late contradiction while retaining only diagnostic provenance', function () {
    $dto = ParentResultProbe::snapshot();
    $dto['status'] = 'failed';
    $dto['resolution'] = 'incomplete';
    $dto['product'] = null;
    $dto['source'] = null;
    $dto['coverage']['sections']['identity'] = ['state' => 'failed', 'complete' => false, 'reasons' => ['late comparable contradiction'], 'evidence_refs' => ['h-identity']];
    $dto['coverage']['groups']['identity']['complete'] = false;
    $dto['coverage']['complete'] = false;
    $dto['diagnostics'][] = ['code' => 'identity_mismatch', 'stage' => 'detail', 'attempt_id' => 'a-detail', 'severity' => 'error', 'recovered' => false, 'message' => 'SYNTHETIC late mismatch', 'section' => 'identity', 'evidence_refs' => ['h-identity']];
    $result = WixLookupResult::fromArray($dto);
    expect($result->product)->toBeNull()->and($result->source)->toBeNull()->and($result->coverage->complete)->toBeFalse()->and($result->evidence)->toHaveCount(3);
    $dto['product'] = ParentResultProbe::snapshot()['product'];
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
});
