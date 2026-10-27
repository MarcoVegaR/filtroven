<?php

use App\Services\Wix\WixLookupService;
use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\Profiles\SurfaceInventory;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;

it('CA01 CA19 preserves invalid literals and blocks work before offline transport', function (string $literal, array $overrides, string $expected) {
    $config = ProductionHarness::configuration($overrides);
    $transport = new ProductionHarness([]);
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch($literal)->toArray();
    ContractWitness::assert($dto);
    expect($dto['requested_code'])->toBe($literal)->and($dto['resolution'])->toBe('not_attempted')
        ->and($dto['diagnostics'][0]['code'])->toBe($expected)->and($transport->requests)->toBe([]);
})->with([
    'empty' => ['', [], 'invalid_input'], 'control' => ["51515\0", [], 'invalid_input'], 'outside space' => ['51515 ', [], 'invalid_input'],
    'length' => [str_repeat('x', 257), [], 'invalid_input'], 'missing cap' => ['51515', ['max_nodes_total' => null], 'invalid_configuration'],
    'infinity' => ['51515', ['total_timeout_ms' => INF], 'invalid_configuration'],
]);

it('CA02 CA04 CA06 CA07 CA08 CA09 CA10 CA11 CA27 emits actual public outcomes with no error fallback', function (string $scenario, string $status, string $resolution, ?string $cause, int $requests) {
    $corpus = ProductionHarness::corpus();
    $negative = $corpus->body('es-co-51515R-search-p1-s15');
    $exact = json_decode($corpus->body('en-ap-51515R-search-p1-s15'), true);
    $config = ProductionHarness::configuration();
    $responses = [];
    switch ($scenario) {
        case 'negative':
        case 'co-only':
            $responses = [$negative, $corpus->body('en-ap-NOEXISTE51515ZZ-search-p1-s15')];
            $config['fallback'] = $scenario !== 'co-only';
            break;
        case 'ambiguous':
            $other = $exact['data']['catalogSearch']['items'][0];
            $other['product']['sku'] = 'SYNTHETIC-SECOND';
            $other['product']['urlKey'] = 'SYNTHETIC-SECOND';
            $exact['data']['catalogSearch']['items'][] = $other;
            $exact['data']['catalogSearch']['totalCount'] = 2;
            $responses = [json_encode($exact)];
            break;
        case 'unverified':
            unset($exact['data']['catalogSearch']['items'][0]['product']['sku']);
            $responses = [json_encode($exact)];
            break;
        case 'page-drift':
            $exact['data']['catalogSearch']['pageInfo']['currentPage'] = 2;
            $responses = [json_encode($exact)];
            break;
        case 'graphql':
            $exact['errors'] = [['message' => 'SYNTHETIC PRIVATE_SENTINEL']];
            $responses = [json_encode($exact)];
            break;
        case 'malformed':
            $responses = ['{'];
            break;
        case 'schema':
            $responses = ['{"data":{"unknown":[]}}'];
            break;
        case '403':
        case '429':
        case '500':
            $responses = [static fn (RequestPlan $r): CapturedResponse => new CapturedResponse($r, (int) $scenario, [], 'PRIVATE_SENTINEL', null, gmdate('Y-m-d\TH:i:s\Z'))];
            break;
        case 'timeout':
        case 'ap-timeout':
            $fail = static function (): never {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'test-timeout');
            };
            $responses = $scenario === 'timeout' ? [$fail] : [$negative, $fail];
            break;
        case 'mismatch':
        case 'unknown-main':
            $html = $corpus->body('en-ap-51515R-detail');
            $html = $scenario === 'mismatch' ? str_replace('data-product-sku="51515R_WIX"', 'data-product-sku="OTHER"', $html) : str_replace('cmp-page__main', 'unknown-main', $html);
            $responses = [json_encode($exact), $html];
            break;
        default:
            throw new LogicException('Unknown acceptance scenario');
    }
    $transport = new ProductionHarness($responses);
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect([$dto['status'], $dto['resolution']])->toBe([$status, $resolution])->and($dto['product'])->toBeNull()->and($dto['source'])->toBeNull()
        ->and($dto['coverage']['complete'])->toBeFalse()->and(count($transport->requests))->toBe($requests);
    if ($cause !== null) {
        expect(array_column($dto['diagnostics'], 'code'))->toContain($cause)->and($dto['diagnostics'][0]['recovered'])->toBeFalse();
    }
    expect(json_encode($dto['diagnostics']))->not->toContain('PRIVATE_SENTINEL');
    if ($scenario === 'ap-timeout') {
        expect($dto['scope']['exhaustive_profiles'])->toBe(['es-co'])->and($dto['scope']['consulted_profiles'])->toBe(['es-co', 'en-ap']);
    } elseif ($scenario === 'negative' || $scenario === 'co-only') {
        expect($dto['scope']['required_profiles'])->toBe($scenario === 'negative' ? ['es-co', 'en-ap'] : ['es-co']);
    } else {
        expect($dto['scope']['consulted_profiles'])->toBe(['es-co']);
    }
})->with([
    'both negative' => ['negative', 'success', 'no_exact_match', null, 2], 'CO only' => ['co-only', 'success', 'no_exact_match', null, 1],
    'two valid identities' => ['ambiguous', 'failed', 'ambiguous', 'ambiguous_identity', 1], 'required identifier missing' => ['unverified', 'failed', 'incomplete', 'identity_unverified', 1],
    'page drift after exact' => ['page-drift', 'failed', 'incomplete', 'pagination_error', 1],
    'data plus errors' => ['graphql', 'failed', 'incomplete', 'graphql_error', 1], 'malformed JSON' => ['malformed', 'failed', 'incomplete', 'parse_error', 1],
    'schema drift' => ['schema', 'failed', 'incomplete', 'schema_error', 1], '403' => ['403', 'failed', 'incomplete', 'http_error', 1],
    '429' => ['429', 'failed', 'incomplete', 'http_error', 1], '500' => ['500', 'failed', 'incomplete', 'http_error', 1],
    'CO timeout' => ['timeout', 'failed', 'incomplete', 'transport_error', 1], 'AP timeout' => ['ap-timeout', 'failed', 'incomplete', 'transport_error', 2],
    'detail mismatch' => ['mismatch', 'failed', 'incomplete', 'identity_mismatch', 2], 'unknown main' => ['unknown-main', 'failed', 'incomplete', 'identity_unverified', 2],
]);

it('CA03 CA14 CA16 CA20 CA22 CA23 CA26 fetches twice through exact Laravel HTTP fakes with stray prevention', function () {
    $corpus = ProductionHarness::corpus();
    $ids = ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0'];
    $http = new Factory;
    $http->preventStrayRequests();
    $expected = [...$ids, ...$ids];
    $http->fake(function (Request $request) use ($corpus, &$expected, $http) {
        if ($expected === []) {
            return null;
        }
        $id = $expected[0];
        $fixture = $corpus->fixture($id);
        if ($request->method() !== 'GET' || $request->url() !== $corpus->requestUrl($id)) {
            return null;
        }
        foreach ($fixture['request']['headers'] as $name => $value) {
            expect($request->hasHeader($name, $value))->toBeTrue();
        }
        array_shift($expected);

        return $http->response($corpus->body($id), 200);
    });
    $transport = new class($http) implements WixTransport
    {
        public function __construct(private Factory $http) {}

        public function send(RequestPlan $request, LookupContext $context): CapturedResponse
        {
            $headers = [];
            foreach ($request->headers as $h) {
                $headers[$h->name] = $h->value;
            }
            $context->budget->begin();
            try {
                $r = $this->http->withHeaders($headers)->get($request->url);
                $context->budget->receive(strlen($r->body()));

                return new CapturedResponse($request, $r->status(), [], $r->body(), null, gmdate('Y-m-d\TH:i:s\Z'));
            } finally {
                $context->budget->finish();
            }
        }
    };
    $config = ProductionHarness::configuration();
    $service = new WixLookupService(fn (): array => $config, $transport);
    $first = $service->fetch('51515R');
    $second = $service->fetch('51515R');
    foreach ([$first, $second] as $result) {
        $dto = $result->toArray();
        ContractWitness::assert($dto, captured: true);
        expect($dto['status'])->toBe('success')->and($dto['source']['profile'])->toBe('en-ap')->and($dto['product']['identity']['code'])->toBe('51515R')
            ->and($dto['meta']['budget_used']['requests'])->toBe(4)->and($dto['product']['media'])->toHaveCount(12);
        $pressures = array_values(array_filter($dto['product']['specifications'], fn ($o): bool => in_array($o['source_key'] ?? null, ['bypass_valve_opening_pressurem', 'bypass_valve_opening_pressure'], true)));
        expect(array_column($pressures, 'value_raw'))->toBe(['0.758', '0.551']);
        expect(array_unique(array_column($dto['evidence'], 'surface')))->toContain('graphql_search', 'html', 'asset_metadata');
    }
    expect($first)->not->toBe($second)->and($first->attempts[0])->not->toBe($second->attempts[0])->and($expected)->toBe([]);
    $http->assertSentCount(8);
    expect(fn () => $http->get('https://example.invalid/unexpected'))->toThrow(StrayRequestException::class);
});

it('CA05 CA06 exhausts derived late and repeated candidates through public fetch', function (bool $repeat) {
    $corpus = ProductionHarness::corpus();
    $pages = array_map(fn (int $p): array => json_decode($corpus->body('es-co-51515-search-p'.$p.'-s1'), true), [1, 2, 3]);
    if ($repeat) {
        $pages = [$pages[0]];
        $pages[0]['data']['catalogSearch']['items'][] = $pages[0]['data']['catalogSearch']['items'][0];
        $pages[0]['data']['catalogSearch']['totalCount'] = 2;
        $pages[0]['data']['catalogSearch']['pageInfo']['totalPages'] = 1;
        $pages[0]['data']['catalogSearch']['pageInfo']['pageSize'] = 15;
    } else {
        [$pages[0]['data']['catalogSearch']['items'], $pages[2]['data']['catalogSearch']['items']] = [$pages[2]['data']['catalogSearch']['items'], $pages[0]['data']['catalogSearch']['items']];
    }
    $transport = new ProductionHarness([...array_map(json_encode(...), $pages), $corpus->body('es-co-51515-detail'), $corpus->body('es-co-51515_WIX-assets-l25-o0')]);
    $config = ProductionHarness::configuration(['page_size' => $repeat ? 15 : 1]);
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect($dto['status'])->toBe('success')->and($dto['attempts'][0]['pages'])->toBe($repeat ? [1] : [1, 2, 3])->and($dto['meta']['budget_used']['requests'])->toBe($repeat ? 3 : 5);
    $refs = $dto['product']['identity']['evidence_refs'];
    expect(array_any($refs, fn (string $ref): bool => str_contains($dto['evidence'][$ref]['source_url'], $repeat ? 'items' : 'currentPage%22%3A3') || ($repeat && str_contains($dto['evidence'][$ref]['locator'], '/items/1/'))))->toBeTrue();
})->with(['derived late exact' => false, 'derived coherent repetition' => true]);

it('CA12 CA13 CA15 CA25 CA29 CA30 certifies only the declared sixteen versioned cells against actual captures', function (string $profile) {
    $corpus = ProductionHarness::corpus();
    $cases = $profile === 'es-co' ? [
        ['51515', ['es-co-51515-search-p1-s1', 'es-co-51515-search-p2-s1', 'es-co-51515-search-p3-s1', 'es-co-51515-detail', 'es-co-51515_WIX-assets-l25-o0'], 1],
        ['42831', ['es-co-42831-search-p1-s15', 'es-co-42831-detail'], 15],
    ] : [
        ['51515R', ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0'], 15],
        ['51515', ['es-co-51515R-search-p1-s15', 'en-ap-51515-search-p1-s15', 'en-ap-51515-detail'], 15],
    ];
    $results = [];
    foreach ($cases as [$code, $ids, $size]) {
        $responses = array_map($corpus->body(...), $ids);
        if (count($ids) < 4) {
            $responses[] = static function (): never {
                throw new OperationalFailure(DiagnosticCode::TransportError, 'metadata-unavailable-in-corpus');
            };
        }
        $config = ProductionHarness::configuration(['page_size' => $size]);
        $dto = (new WixLookupService(fn (): array => $config, new ProductionHarness($responses)))->fetch($code)->toArray();
        ContractWitness::assert($dto, captured: true);
        expect($dto['source']['profile'])->toBe($profile)->and($dto['meta']['parser_version'])->toBe(WixLookupService::ParserVersion)
            ->and($dto['meta']['profile_versions'][$profile])->toBe(SurfaceInventory::PROFILE_VERSION);
        foreach ($ids as $id) {
            expect($corpus->fixture($id)['profile'])->toBeIn(['es-co', 'en-ap']);
            expect(hash('sha256', $corpus->body($id)))->toHaveLength(64);
        }
        $results[] = $dto;
    }
    foreach (CoverageSections::NAMES as $section) {
        $complete = $results[0]['coverage']['sections'][$section];
        expect($complete['complete'])->toBeTrue()->and($complete['evidence_refs'])->not->toBeEmpty();
        $surfaces = array_unique(array_map(fn (string $ref): string => $results[0]['evidence'][$ref]['surface'], $complete['evidence_refs']));
        foreach ((new SurfaceInventory($profile))->surfaces($section) as $surface) {
            expect($surfaces)->toContain($surface->value);
        }
        if ($section === 'identity') {
            expect($results[0]['product']['identity']['brand'])->toBe('WIX');
        } elseif ($section === 'documents') {
            expect($complete['state'])->toBe('published_empty')->and($results[0]['product']['documents'])->toBe([])
                ->and((new SurfaceInventory($profile))->supportsPopulatedDocuments())->toBeFalse();
        } else {
            expect(array_any($results, fn (array $r): bool => $r['product'][$section] !== [] && $r['coverage']['sections'][$section]['complete']))->toBeTrue();
        }
    }
    $populated = $results[1];
    expect($populated['product']['applications'])->toHaveCount($profile === 'es-co' ? 11 : 94)
        ->and(array_sum(array_map(fn (array $a): int => count($a['occurrences']), $populated['product']['applications'])))->toBe($profile === 'es-co' ? 22 : 188)
        ->and($populated['coverage']['groups']['technical']['complete'])->toBeTrue()->and($populated['coverage']['groups']['resources']['complete'])->toBeFalse()
        ->and($populated['status'])->toBe('partial')->and($populated['coverage']['sections']['media']['complete'])->toBeFalse();

    [$code, $ids, $size] = $cases[0];
    $derived = array_map(function (string $id) use ($corpus, $profile): string {
        $raw = $corpus->body($id);
        if (str_ends_with($id, '-detail')) {
            $offset = strpos($raw, 'id="downloads"');
            $marker = $profile === 'es-co' ? 'No hay información disponible' : 'No Data Available';
            $raw = substr($raw, 0, $offset).preg_replace('/'.preg_quote($marker, '/').'/', '<a href="https://example.invalid/SYNTHETIC.pdf">Synthetic populated document</a>', substr($raw, $offset), 1);
        }

        return $raw;
    }, $ids);
    $config = ProductionHarness::configuration(['page_size' => $size]);
    $transport = new ProductionHarness($derived);
    $unsupported = (new WixLookupService(fn (): array => $config, $transport))->fetch($code)->toArray();
    ContractWitness::assert($unsupported, captured: true);
    expect($unsupported['status'])->toBe('partial')->and($unsupported['product']['documents'])->toBe([])
        ->and($unsupported['coverage']['sections']['documents']['state'])->toBe('unsupported')->and($unsupported['coverage']['sections']['documents']['complete'])->toBeFalse()
        ->and($unsupported['coverage']['groups']['technical']['complete'])->toBeTrue()->and($unsupported['coverage']['sections']['media']['complete'])->toBeTrue()
        ->and(count($transport->requests))->toBe(count($ids));
    expect(array_any($unsupported['diagnostics'], fn (array $d): bool => $d['section'] === 'documents' && $d['code'] === 'unsupported_section' && $d['severity'] === 'error' && ! $d['recovered']))->toBeTrue();
})->with(['CO versioned eight cells' => 'es-co', 'AP versioned eight cells' => 'en-ap']);

it('CA24 keeps delivery evidence separate from production enablement and missing operator-free runtime configuration', function () {
    $config = require __DIR__.'/../../../config/wix.php';
    expect($config['enabled'])->toBeTrue();
    $config = ProductionHarness::configuration();
    $dto = (new WixLookupService(fn (): array => $config))->fetch('51515')->toArray();
    ContractWitness::assert($dto);
    expect($dto['resolution'])->toBe('not_attempted')->and($dto['diagnostics'][0]['code'])->toBe('invalid_configuration')
        ->and($dto['attempts'])->toBe([])->and($dto['meta']['budget_used']['requests'])->toBe(0);
    foreach (['es-co', 'en-ap'] as $profile) {
        expect((new ProfileRegistry)->get($profile)->inventory()->documentGap())->toBe('AUTHENTIC_POSITIVE_DOCUMENT_MISSING');
    }
});

it('CA21 rejects a contradictory completed public capture independently of the production validator', function () {
    $corpus = ProductionHarness::corpus();
    $config = ProductionHarness::configuration();
    $transport = new ProductionHarness(array_map($corpus->body(...), ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0']));
    $dto = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
    ContractWitness::assert($dto, captured: true);
    expect(WixLookupResult::fromArray($dto)->toArray())->toBe($dto);
    $dto['product'] = null;
    expect(fn () => ContractWitness::assert($dto, captured: true))->toThrow(LogicException::class);
    expect(fn () => WixLookupResult::fromArray($dto))->toThrow(LogicException::class);
});
