<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;

it('closes new admission when config is reloaded rather than caching an enabled flag', function () {
    $config = ProductionHarness::configuration();
    $config['enabled'] = false;
    $transport = new ProductionHarness([]);
    $service = new WixLookupService(function () use (&$config): array {
        return $config;
    }, $transport);
    $result = $service->fetch('51515');
    expect($result->resolution->value)->toBe('not_attempted')->and($transport->requests)->toBe([]);
});

it('stops inflight before identity without resetting history', function () {
    $config = ProductionHarness::configuration();
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness([function () use (&$config, $corpus): string {
        $config['enabled'] = false;

        return $corpus->body('es-co-51515R-search-p1-s15');
    }]);
    $result = (new WixLookupService(function () use (&$config): array {
        return $config;
    }, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($result);
    expect($result['resolution'])->toBe('incomplete')->and($result['meta']['budget_used']['requests'])->toBe(1)->and($result['diagnostics'][0]['code'])->toBe('disabled');
});

it('returns a causal partial product after identity when the kill-switch closes an inflight asset response', function () {
    $config = ProductionHarness::configuration();
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-detail'), function () use (&$config, $corpus): string {
        $config['enabled'] = false;

        return $corpus->body('en-ap-51515R_WIX-assets-l25-o0');
    }]);
    $service = new WixLookupService(function () use (&$config): array {
        return $config;
    }, $transport);
    $result = $service->fetch('51515R')->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['status'])->toBe('partial')->and($result['product']['media'])->toHaveCount(8)->and($result['meta']['budget_used']['requests'])->toBe(4)
        ->and($result['coverage']['groups']['technical']['complete'])->toBeTrue()->and($result['diagnostics'][0]['code'])->toBe('disabled');
    expect($service->fetch('51515R')->resolution->value)->toBe('not_attempted');
});

it('cancels and reaps an owned native parser when the caller reloads the disabled configuration', function () {
    $base = ProductionHarness::context();
    $checks = 0;
    $budget = new LookupBudget($base->budget->limits, enabled: function () use (&$checks): bool {
        return ++$checks < 20;
    });
    $context = new LookupContext('51515', $budget, gmdate('Y-m-d\TH:i:s\Z'), new EvidenceBuilder(16384));
    $supervisor = new SupervisedPhpTask;
    $reader = new SupervisedDocumentReader($supervisor);
    $response = new CapturedResponse((new ProfileRegistry)->get('es-co')->detail('51515_wix', 'detail'), 200, [], '<html><body>'.str_repeat('<div>bounded</div>', 10000).'</body></html>', null, gmdate('Y-m-d\TH:i:s\Z'));
    expect(fn () => $reader->read($response, $context))->toThrow(OperationalFailure::class, 'disabled');
    expect($supervisor->reaped)->toBeTrue()->and($supervisor->lastPid)->not->toBeNull()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse()
        ->and(hrtime(true))->toBeLessThan($budget->deadline);
});
