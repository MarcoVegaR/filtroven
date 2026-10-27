<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\ParentResultProbe;

it('lets a direct consumer use technical complete independently of resource completeness', function () {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-detail'), function (): never {
        throw new OperationalFailure(DiagnosticCode::TransportError, 'assets');
    }]);
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R');
    $payload = $dto->toArray();
    ContractWitness::assert($payload, captured: true);
    expect($dto->coverage->groups->technical->complete)->toBeTrue()->and($dto->coverage->groups->resources->complete)->toBeFalse()
        ->and($payload['status'])->toBe('partial')->and(WixLookupResult::fromArray($payload)->toArray())->toBe($payload);
});

it('handles every contract pair explicitly without interpreting null as no-match', function (string $scenario, string $expected) {
    $raw = ParentResultProbe::snapshot($scenario);
    ContractWitness::assert($raw);
    $dto = WixLookupResult::fromArray($raw);
    $decision = match ($dto->status->value.'/'.$dto->resolution->value) {
        'success/exact' => 'complete-product', 'partial/exact' => 'scoped-partial-product', 'success/no_exact_match' => 'bounded-absence',
        'failed/ambiguous' => 'ambiguous', 'failed/incomplete' => 'unknown', 'failed/not_attempted' => 'not-queried',
    };
    expect($decision)->toBe($expected)->and($dto->toArray())->toEqual($raw);
})->with([['exact', 'complete-product'], ['partial', 'scoped-partial-product'], ['negative', 'bounded-absence'], ['ambiguous', 'ambiguous'], ['incomplete', 'unknown'], ['not_attempted', 'not-queried']]);
