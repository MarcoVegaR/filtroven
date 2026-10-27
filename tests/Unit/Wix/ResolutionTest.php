<?php

use App\Support\Wix\CandidateResolver;
use App\Support\Wix\Data\BudgetSnapshot;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\LookupAttempt;
use App\Support\Wix\DetailVerifier;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\RegionalLookupPolicy;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use App\Support\Wix\SearchPaginator;
use Tests\Support\Wix\ProductionHarness;

it('materializes exact profile requests and all sixteen fixed corpus bindings', function () {
    $corpus = ProductionHarness::corpus();
    $registry = new ProfileRegistry;
    foreach ($corpus->ids() as $id) {
        $fixture = $corpus->fixture($id);
        $profile = $registry->get($fixture['profile']);
        if ($fixture['surface'] === 'graphql_search') {
            $v = $fixture['request']['graphql']['variables'];
            $request = $profile->search($v['search'], $v['currentPage'], $v['pageSize'], 'search');
            expect($request->url)->toBe($corpus->requestUrl($id));
            $headers = [];
            foreach ($request->headers as $header) {
                $headers[$header->name] = $header->value;
            }
            expect($headers)->toBe($fixture['request']['headers']);
        }
    }
    foreach (['es-co', 'en-ap'] as $id) {
        $profile = $registry->get($id);
        expect(count($profile->fixtureBindings()))->toBe(8)->and($profile->inventory()->supportsPopulatedDocuments())->toBeFalse()
            ->and($profile->inventory()->documentGap())->toBe('AUTHENTIC_POSITIVE_DOCUMENT_MISSING');
        foreach ($profile->fixtureBindings() as $ids) {
            foreach ($ids as $fixture) {
                expect($corpus->fixture($fixture)['profile'])->toBe($id);
            }
        }
    }
    expect(fn () => $registry->get('en-us'))->toThrow(LogicException::class)
        ->and(fn () => $registry->get('es-co')->detail('../51515', 'detail'))->toThrow(OperationalFailure::class);
});

it('exhausts all three authentic search pages before unique resolution', function () {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map(fn (int $page): string => $corpus->body('es-co-51515-search-p'.$page.'-s1'), [1, 2, 3]));
    $context = ProductionHarness::context('51515', ['page_size' => 1]);
    $result = (new SearchPaginator($transport, new SupervisedDocumentReader(new SupervisedPhpTask)))->search((new ProfileRegistry)->get('es-co'), $context, 'search');
    expect($result->exhaustive)->toBeTrue()->and($result->pages)->toBe([1, 2, 3])->and(count($result->candidates))->toBe(1)
        ->and($context->budget->snapshot()->requests)->toBe(3)->and($result->candidates[0]['code'])->toBe('51515');
});

it('never promotes a candidate or absence after broken or capped pagination', function (Closure $mutate, string $expected) {
    $corpus = ProductionHarness::corpus();
    $first = json_decode($corpus->body('es-co-51515-search-p1-s1'), true);
    $second = json_decode($corpus->body('es-co-51515-search-p2-s1'), true);
    $mutate($first, $second);
    $transport = new ProductionHarness([json_encode($first), json_encode($second)]);
    $context = ProductionHarness::context('51515', ['page_size' => 1, 'max_search_pages' => 2]);
    $result = (new SearchPaginator($transport, new SupervisedDocumentReader(new SupervisedPhpTask)))->search((new ProfileRegistry)->get('es-co'), $context, 'search');
    expect($result->exhaustive)->toBeFalse()->and($result->negative())->toBeFalse()->and($result->failure?->value)->toBe($expected);
})->with([
    'page cap' => [static function (array &$a, array &$b): void {}, 'budget_exhausted'],
    'omitted page' => [static function (array &$a, array &$b): void {
        $b['data']['catalogSearch']['pageInfo']['currentPage'] = 3;
    }, 'pagination_error'],
    'repeated page' => [static function (array &$a, array &$b): void {
        $b = $a;
    }, 'pagination_error'],
    'totals drift' => [static function (array &$a, array &$b): void {
        $b['data']['catalogSearch']['totalCount'] = 4;
    }, 'pagination_error'],
    'errors plus data' => [static function (array &$a, array &$b): void {
        $a['errors'] = [['message' => 'synthetic']];
    }, 'graphql_error'],
    'string page' => [static function (array &$a, array &$b): void {
        $a['data']['catalogSearch']['pageInfo']['currentPage'] = '1';
    }, 'pagination_error'],
]);

it('matches literal brand and designation rather than SKU titles or suffixes', function (string $requested, string $designation, string $brand, int $expected) {
    $resolver = new CandidateResolver;
    $resolver->admit(['sku' => 'not-a-universal-code', 'urlKey' => 'published-key', 'name' => 'WIX '.$requested,
        'attributes' => [['key' => 'sales_designation', 'value' => $designation], ['key' => 'product_brand', 'value' => $brand, 'adminValue' => 'wix']]], $requested, 'e-1');
    expect(count($resolver->all()))->toBe($expected);
})->with([['51515', '51515', 'WIX', 1], ['51515', '51515MP', 'WIX', 0], ['51515', '51515XP', 'WIX', 0], ['51515', '51515R', 'WIX', 0], ['051515', '51515', 'WIX', 0], ['51515r', '51515R', 'WIX', 0], ['51515', '51515', 'wix', 0], ['WIX51515', '51515', 'WIX', 0]]);

it('preserves repeated identity occurrences but rejects incompatible comparable identifiers', function () {
    $product = json_decode(ProductionHarness::corpus()->body('en-ap-51515R-search-p1-s15'), true)['data']['catalogSearch']['items'][0]['product'];
    $resolver = new CandidateResolver;
    $resolver->admit($product, '51515R', 'e-1');
    $resolver->admit($product, '51515R', 'e-2');
    expect($resolver->all()[0]['evidence_refs'])->toBe(['e-1', 'e-2']);
    $product['urlKey'] = 'different';
    expect(fn () => $resolver->admit($product, '51515R', 'e-3'))->toThrow(OperationalFailure::class, 'identity_conflict');
    unset($product['sku']);
    expect(fn () => (new CandidateResolver)->admit($product, '51515R', 'e-3'))->toThrow(OperationalFailure::class, 'identity_unverified');
});

it('performs genuine CO negative to AP exact identity with one shared budget and no merge', function () {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map($corpus->body(...), ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail']));
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('51515R');
    $deadline = $context->budget->deadline;
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->resolution)->toBe(LookupResolution::Exact)->and($result->scope->consulted_profiles)->toBe(['es-co', 'en-ap'])
        ->and($result->identity->identity()->code)->toBe('51515R')->and($result->identity->source()->profile)->toBe('en-ap')
        ->and($context->budget->snapshot()->requests)->toBe(3)->and($context->budget->deadline)->toBe($deadline)->and(count($context->attempts()))->toBe(3);
    $dto = ProductionHarness::assertContract($result, $context);
    expect($dto['status'])->toBe('partial')->and($dto['coverage']['sections']['identity']['complete'])->toBeTrue()->and($dto['coverage']['complete'])->toBeFalse();
    $contradictionRequest = (new ProfileRegistry)->get('en-ap')->detail('51515r_wix', 'identity-recheck');
    $capturedAt = gmdate('Y-m-d\TH:i:s\Z');
    $ref = $context->evidence->capture(new CapturedResponse($contradictionRequest, 200, [], 'contradiction', null, $capturedAt), '/synthetic/contradiction', 'different SKU');
    $context->recordAttempt(new LookupAttempt('identity-recheck', 'en-ap', 'identity-recheck', [], 0, 'identity_mismatch', [$ref], $capturedAt, $capturedAt, new BudgetSnapshot(0, 0, 0, 0, 0, 0)));
    $result->identity->compare('gtin', '765809129054', $context, $ref);
    expect($result->identity->identity())->not->toBeNull();
    $result->identity->compare('sku', 'different', $context, $ref);
    expect($result->identity->identity())->toBeNull()->and($result->identity->source())->toBeNull()->and(count($context->evidence->all()))->toBeGreaterThan(5)
        ->and($context->diagnostics()[0]->code)->toBe(DiagnosticCode::IdentityMismatch);
    $invalidated = ProductionHarness::assertContract($result, $context);
    expect($invalidated['resolution'])->toBe('incomplete')->and($invalidated['product'])->toBeNull()->and($invalidated['source'])->toBeNull()
        ->and($invalidated['coverage']['sections']['identity']['state'])->toBe('failed')->and($invalidated['scope']['conclusion'])->toBe('incomplete');
});

it('blocks AP rescue for primary errors or detail mismatches', function (bool $searchError) {
    $corpus = ProductionHarness::corpus();
    $search = $corpus->body('es-co-42831-search-p1-s15');
    if ($searchError) {
        $search = '{"data":null,"errors":[{"message":"synthetic"}]}';
    }
    $detail = str_replace('"sku":"42831_WIX"', '"sku":"other"', $corpus->body('es-co-42831-detail'));
    $transport = new ProductionHarness([$search, $detail]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('42831');
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->resolution)->toBe(LookupResolution::Incomplete)->and($result->identity)->toBeNull()->and($result->scope->consulted_profiles)->toBe(['es-co']);
    expect(count($transport->requests))->toBe($searchError ? 1 : 2);
    expect(ProductionHarness::assertContract($result, $context)['product'])->toBeNull();
})->with([true, false]);

it('limits absence to required profiles and keeps exhausted fallback inconclusive', function (bool $fallback, bool $exhaustBudget) {
    $negative = ProductionHarness::corpus()->body('es-co-51515R-search-p1-s15');
    $transport = new ProductionHarness([$negative, $negative]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('51515R', $exhaustBudget ? ['max_requests' => 1] : []);
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry, $fallback))->resolve($context);
    expect($result->resolution)->toBe($exhaustBudget && $fallback ? LookupResolution::Incomplete : LookupResolution::NoExactMatch)
        ->and($result->scope->required_profiles)->toBe($fallback ? ['es-co', 'en-ap'] : ['es-co'])->and($result->identity)->toBeNull();
    expect($context->budget->snapshot()->requests)->toBe($fallback && ! $exhaustBudget ? 2 : 1);
    expect(ProductionHarness::assertContract($result, $context)['coverage']['complete'])->toBeFalse();
})->with([[true, false], [false, false], [true, true]]);

it('finds a deliberately derived late exact without certifying it as an authentic ordering', function () {
    $corpus = ProductionHarness::corpus();
    $pages = array_map(fn (int $page): array => json_decode($corpus->body('es-co-51515-search-p'.$page.'-s1'), true), [1, 2, 3]);
    [$pages[0]['data']['catalogSearch']['items'], $pages[2]['data']['catalogSearch']['items']] = [$pages[2]['data']['catalogSearch']['items'], $pages[0]['data']['catalogSearch']['items']];
    $transport = new ProductionHarness(array_map(json_encode(...), $pages));
    $context = ProductionHarness::context('51515', ['page_size' => 1]);
    $result = (new SearchPaginator($transport, new SupervisedDocumentReader(new SupervisedPhpTask)))->search((new ProfileRegistry)->get('es-co'), $context, 'search');
    expect($result->exhaustive)->toBeTrue()->and($result->pages)->toBe([1, 2, 3])->and(count($result->candidates))->toBe(1);
    expect($context->evidence->all()[$result->candidates[0]['evidence_refs'][0]]->source_url)->toContain('currentPage%22%3A3');
});

it('distinguishes early ambiguity from conflict or unverified IDs and never rescues with AP', function (string $scenario, string $failure) {
    $first = json_decode(ProductionHarness::corpus()->body('es-co-51515-search-p1-s1'), true);
    $second = $first;
    $second['data']['catalogSearch']['pageInfo']['currentPage'] = 2;
    $product = &$second['data']['catalogSearch']['items'][0]['product'];
    $product['urlKey'] = 'other_key';
    if ($scenario === 'ambiguous') {
        $product['sku'] = 'distinct_published_sku';
    } elseif ($scenario === 'unverified') {
        unset($product['sku']);
    }
    unset($product);
    $transport = new ProductionHarness([json_encode($first), json_encode($second)]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('51515', ['page_size' => 1]);
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->searches[0]->exhaustive)->toBeFalse()->and($result->scope->consulted_profiles)->toBe(['es-co'])->and($result->searches[0]->failure->value)->toBe($failure)
        ->and(count($transport->requests))->toBe(2)->and($result->identity)->toBeNull();
    expect(ProductionHarness::assertContract($result, $context)['resolution'])->toBe($scenario === 'ambiguous' ? 'ambiguous' : 'incomplete');
})->with([['ambiguous', 'ambiguous_identity'], ['conflict', 'identity_conflict'], ['unverified', 'identity_unverified']]);

it('does not confuse an empty object with an empty item collection', function () {
    $body = json_decode(ProductionHarness::corpus()->body('es-co-51515R-search-p1-s15'), true);
    $body['data']['catalogSearch']['items'] = new stdClass;
    $transport = new ProductionHarness([json_encode($body)]);
    $result = (new SearchPaginator($transport, new SupervisedDocumentReader(new SupervisedPhpTask)))->search((new ProfileRegistry)->get('es-co'), ProductionHarness::context('51515R'), 'search');
    expect($result->negative())->toBeFalse()->and($result->failure)->toBe(DiagnosticCode::SchemaError);
});

it('requires recognized linked main, visible literal designation, JSON-LD and published form SKU', function (Closure $mutate, string $expected) {
    $corpus = ProductionHarness::corpus();
    $body = $mutate($corpus->body('en-ap-51515R-detail'));
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $body]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('51515R');
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->resolution)->toBe($expected === 'exact' ? LookupResolution::Exact : LookupResolution::Incomplete)
        ->and(count($transport->requests))->toBe(3);
    if ($expected !== 'exact') {
        expect($result->identity)->toBeNull()->and($context->diagnostics()[0]->code->value)->toBe($expected);
    }
    ProductionHarness::assertContract($result, $context);
})->with([
    'unknown main' => [fn (string $s): string => str_replace('cmp-page__main', 'unknown-main', $s), 'identity_unverified'],
    'visible mismatch' => [fn (string $s): string => str_replace('role="name">51515R', 'role="name">51515r', $s), 'identity_mismatch'],
    'form mismatch' => [fn (string $s): string => str_replace('data-product-sku="51515R_WIX"', 'data-product-sku="other"', $s), 'identity_mismatch'],
    'missing brand' => [fn (string $s): string => str_replace('"brand":{"@type":"Brand","name":"WIX"}', '"brand":null', $s), 'identity_unverified'],
    'malformed LD' => [fn (string $s): string => str_replace('"@context":"http://schema.org/"', 'invalid', $s), 'parse_error'],
    'GTIN is not SKU' => [fn (string $s): string => str_replace('765809129054', '000000000000', $s), 'exact'],
]);

it('retains primary negative history when fallback fails by network, bytes or shared time', function (string $cause) {
    $corpus = ProductionHarness::corpus();
    $context = ProductionHarness::context('51515R', $cause === 'bytes' ? ['max_decoded_bytes_per_response' => 6000, 'max_decoded_bytes_total' => 6000] : []);
    $now = hrtime(true);
    if ($cause === 'time') {
        $context = new LookupContext('51515R', new LookupBudget($context->budget->limits, null, function () use (&$now): int {
            return $now;
        }), gmdate('Y-m-d\TH:i:s\Z'), new EvidenceBuilder(16384));
    }
    $second = $cause === 'network' ? static function (): never {
        throw new OperationalFailure(DiagnosticCode::TransportError, 'synthetic-network');
    }
    : function () use ($cause, &$now, $context, $corpus): string {
        if ($cause === 'time') {
            $now = $context->budget->deadline;
        }

        return $corpus->body('en-ap-51515R-search-p1-s15');
    };
    $transport = new ProductionHarness([$corpus->body('es-co-51515R-search-p1-s15'), $second]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $deadline = $context->budget->deadline;
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->resolution)->toBe(LookupResolution::Incomplete)->and($result->scope->exhaustive_profiles)->toBe(['es-co'])
        ->and($result->scope->consulted_profiles)->toBe(['es-co', 'en-ap'])->and($context->budget->deadline)->toBe($deadline)
        ->and($context->budget->snapshot()->requests)->toBe(2)->and($context->attempts()[0]->outcome)->toBe('exhaustive_negative');
    expect(ProductionHarness::assertContract($result, $context)['product'])->toBeNull();
})->with(['network', 'bytes', 'time']);

it('preserves confirmed production identity and evidence across native child failure while independent bounded work continues', function (bool $cancel) {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness([$corpus->body('es-co-42831-search-p1-s15'), $corpus->body('es-co-42831-detail')]);
    $supervisor = new SupervisedPhpTask;
    $reader = new SupervisedDocumentReader($supervisor);
    $context = ProductionHarness::context('42831');
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    $identity = $result->identity->identity();
    $evidence = $context->evidence->all();
    $deadline = $context->budget->deadline;
    try {
        $supervisor->run($cancel ? 'html' : 'json', ['max_nodes' => 250000], $cancel ? '<body>'.str_repeat('<i>x</i>', 200000).'</body>' : '{',
            min($deadline - 250000000, hrtime(true) + 1000000000), 16777216, static function (string $frame): void {}, $cancel ? fn (): bool => $supervisor->nativeStarted : null);
        throw new LogicException('Expected native failure');
    } catch (OperationalFailure $failure) {
        expect($failure->diagnosticCode)->toBe($cancel ? DiagnosticCode::BudgetExhausted : DiagnosticCode::ParseError);
        $context->diagnose((new OperationalFailure($failure->diagnosticCode, 'bounded-independent-unit', null, 'documents'))->diagnostic());
    }
    expect($supervisor->reaped)->toBeTrue()->and($result->identity->identity())->toBe($identity)->and($context->evidence->all())->toBe($evidence);
    $independent = new CapturedResponse((new ProfileRegistry)->get('es-co')->detail('42831_wix', 'detail-es-co'), 200, [], '<html><body><p>independent inert unit</p></body></html>', null, gmdate('Y-m-d\TH:i:s\Z'));
    expect($reader->read($independent, $context)->complete)->toBeTrue()->and($context->budget->deadline)->toBe($deadline)
        ->and($result->scope->consulted_profiles)->toBe(['es-co'])->and(count($transport->requests))->toBe(2);
    $dto = ProductionHarness::assertContract($result, $context);
    expect($dto['status'])->toBe('partial')->and($dto['resolution'])->toBe('exact')->and($dto['product']['identity']['code'])->toBe('42831');
})->with([true, false]);

it('does not hide contradictory references when a repeated product stops matching the requested identity', function (bool $reverse, string $changed) {
    $first = json_decode(ProductionHarness::corpus()->body('es-co-51515-search-p1-s1'), true);
    $first['data']['catalogSearch']['totalCount'] = 2;
    $first['data']['catalogSearch']['pageInfo']['totalPages'] = 2;
    $second = $first;
    foreach ($second['data']['catalogSearch']['items'][0]['product']['attributes'] as &$attribute) {
        if ($attribute['key'] === $changed) {
            $attribute['value'] = $changed === 'sales_designation' ? '51515MP' : 'OTHER';
        }
    }
    unset($attribute);
    if ($reverse) {
        [$first, $second] = [$second, $first];
    }
    $second['data']['catalogSearch']['pageInfo']['currentPage'] = 2;
    $transport = new ProductionHarness([json_encode($first), json_encode($second)]);
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context('51515', ['page_size' => 1]);
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), new ProfileRegistry))->resolve($context);
    expect($result->resolution)->toBe(LookupResolution::Incomplete)
        ->and($result->searches[0]->failure)->toBe(DiagnosticCode::IdentityConflict)
        ->and($result->searches[0]->exhaustive)->toBeFalse()
        ->and($result->scope->consulted_profiles)->toBe(['es-co'])
        ->and(count($transport->requests))->toBe(2);
    ProductionHarness::assertContract($result, $context);
})->with([false, true])->with(['sales_designation', 'product_brand']);

it('verifies identity against each authentic detail without extrapolating technical capture support', function (string $profileId, string $code, array $searchIds, string $detailId, int $pageSize) {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map($corpus->body(...), [...$searchIds, $detailId]));
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $context = ProductionHarness::context($code, ['page_size' => $pageSize]);
    $profile = (new ProfileRegistry)->get($profileId);
    $search = (new SearchPaginator($transport, $reader))->search($profile, $context, 'search');
    expect($search->exhaustive)->toBeTrue()->and($search->failure)->toBeNull()->and($search->candidates)->toHaveCount(1);
    $ledger = (new DetailVerifier($transport, $reader))->verify($profile, $search->candidates[0], $context, 'detail');
    expect($ledger->identity()->code)->toBe($code)->and($ledger->source()->profile)->toBe($profileId)
        ->and($ledger->source()->url)->toBe($corpus->requestUrl($detailId));
})->with([
    ['es-co', '51515', ['es-co-51515-search-p1-s1', 'es-co-51515-search-p2-s1', 'es-co-51515-search-p3-s1'], 'es-co-51515-detail', 1],
    ['es-co', '42831', ['es-co-42831-search-p1-s15'], 'es-co-42831-detail', 15],
    ['en-ap', '51515R', ['en-ap-51515R-search-p1-s15'], 'en-ap-51515R-detail', 15],
    ['en-ap', '51515', ['en-ap-51515-search-p1-s15'], 'en-ap-51515-detail', 15],
]);
